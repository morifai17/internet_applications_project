<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Exceptions\InventoryUnavailableException;
use App\Modules\Orders\Models\Order;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Requirement 2, slide 12: what the application does when the inventory
 * service says no, is down or slow, or when saving fails after a reservation.
 */
final class InventoryServiceFailuresTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_short_line_rejects_the_whole_order_with_a_clear_message(): void
    {
        $customer = User::factory()->create();
        $monitor = Product::factory()->withStock(5)->create(['title' => 'Monitor']);
        $keyboard = Product::factory()->withStock(3)->create(['title' => 'Keyboard']);

        $this->actingAs($customer)->postJson(route('cart.store', $monitor), ['quantity' => 2])->assertOk();
        $this->postJson(route('cart.store', $keyboard), ['quantity' => 3])->assertOk();

        // Someone else buys two keyboards in the meantime.
        $this->inventory->setStock($keyboard->id, 1);

        $this->postJson(route('orders.store'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'conflict')
            ->assertJsonPath('message', 'Only 1 unit(s) of "Keyboard" are available; you requested 3.');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $this->inventory->stockOf($monitor), 'the line that fitted was not taken either');
        $this->assertSame(1, $this->inventory->stockOf($keyboard));
    }

    public function test_checkout_answers_503_at_once_when_the_inventory_service_is_down(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->withStock(5)->create();

        $this->actingAs($customer)->postJson(route('cart.store', $product), ['quantity' => 2])->assertOk();

        $this->inventory->goDown();

        $this->postJson(route('orders.store'))
            ->assertStatus(503)
            ->assertHeader('Retry-After', '5')
            ->assertExactJson([
                'status' => 503,
                'code' => 'service_unavailable',
                'message' => InventoryUnavailableException::MESSAGE,
            ]);

        $this->assertDatabaseCount('orders', 0);

        // The cart is kept, so the customer can retry once the service is back.
        $this->inventory->comeBack();
        $this->getJson(route('cart.index'))->assertOk()->assertJsonPath('data.item_count', 2);
    }

    public function test_a_reservation_whose_answer_was_lost_is_released_again(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->withStock(5)->create();

        $this->actingAs($customer)->postJson(route('cart.store', $product), ['quantity' => 2])->assertOk();

        // The inventory takes the stock, but the deadline expires before the
        // answer arrives.
        $this->inventory->timeOutAfterNextReserve();

        $this->postJson(route('orders.store'))->assertStatus(503);

        $this->assertDatabaseCount('orders', 0);
        $this->assertCount(1, $this->inventory->releaseCalls);
        $this->assertSame(5, $this->inventory->stockOf($product), 'no unit is lost');
    }

    public function test_the_reservation_is_released_when_the_order_cannot_be_saved(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->withStock(5)->create();

        $this->actingAs($customer)->postJson(route('cart.store', $product), ['quantity' => 3])->assertOk();

        // Saving the order fails after the inventory has reserved the stock.
        Order::creating(fn (): never => throw new RuntimeException('Database write failed'));

        $this->postJson(route('orders.store'))->assertServerError();

        $this->assertDatabaseCount('orders', 0);
        $this->assertCount(1, $this->inventory->releaseCalls);
        $this->assertSame(5, $this->inventory->stockOf($product), 'the reserved units went back to stock');
    }

    public function test_an_order_keeps_its_reservation_and_cancelling_releases_it(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $product = Product::factory()->withStock(5)->create();

        $this->actingAs($customer)->postJson(route('cart.store', $product), ['quantity' => 2])->assertOk();
        $this->postJson(route('orders.store'))->assertCreated();

        $order = Order::query()->sole();
        $this->assertNotNull($order->inventory_reservation_id);
        $this->assertSame(3, $this->inventory->stockOf($product));

        $this->actingAs($admin)
            ->patchJson(route('admin.orders.status', $order), ['status' => 'cancelled'])
            ->assertOk();

        $this->assertSame([$order->inventory_reservation_id], $this->inventory->releaseCalls);
        $this->assertSame(5, $this->inventory->stockOf($product));
    }

    public function test_cancelling_while_the_inventory_service_is_down_keeps_the_order_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $product = Product::factory()->withStock(5)->create();

        $this->actingAs($customer)->postJson(route('cart.store', $product), ['quantity' => 2])->assertOk();
        $this->postJson(route('orders.store'))->assertCreated();
        $order = Order::query()->sole();

        $this->inventory->goDown();

        $this->actingAs($admin)
            ->patchJson(route('admin.orders.status', $order), ['status' => 'cancelled'])
            ->assertStatus(503);

        $this->assertSame('pending', $order->fresh()->status->value);

        // Once the service is back, the same request works.
        $this->inventory->comeBack();

        $this->actingAs($admin)
            ->patchJson(route('admin.orders.status', $order), ['status' => 'cancelled'])
            ->assertOk();

        $this->assertSame(5, $this->inventory->stockOf($product));
    }

    public function test_browsing_still_works_with_stock_shown_as_unknown_when_the_service_is_down(): void
    {
        $product = Product::factory()->withStock(5)->create();

        $this->inventory->goDown();

        $this->getJson(route('home'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.stock_quantity', null)
            ->assertJsonPath('data.0.stock_status', 'unknown');

        $this->getJson(route('products.show', $product))
            ->assertOk()
            ->assertJsonPath('data.stock_quantity', null);
    }

    public function test_product_detail_shows_the_stock_reported_by_the_inventory_service(): void
    {
        $product = Product::factory()->withStock(3)->create();

        $this->getJson(route('products.show', $product))
            ->assertOk()
            ->assertJsonPath('data.stock_quantity', 3)
            ->assertJsonPath('data.in_stock', true)
            ->assertJsonPath('data.low_stock', true)
            ->assertJsonPath('data.stock_status', 'low_stock');
    }

    public function test_creating_a_product_while_the_service_is_down_creates_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Product::factory()->create()->category;

        $this->inventory->goDown();

        $this->actingAs($admin)
            ->postJson(route('admin.products.store'), [
                'title' => 'Orphan Product',
                'description' => 'Must not exist without stock.',
                'price' => '10.00',
                'stock_quantity' => 4,
                'category_id' => $category->id,
            ])
            ->assertStatus(503);

        $this->assertDatabaseMissing('products', ['title' => 'Orphan Product']);
    }

    public function test_changing_stock_while_the_service_is_down_answers_503(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->withStock(5)->create();

        $this->inventory->goDown();

        $this->actingAs($admin)
            ->patchJson(route('admin.products.stock', $product), ['stock_quantity' => 9])
            ->assertStatus(503);

        $this->inventory->comeBack();
        $this->assertSame(5, $this->inventory->stockOf($product));
    }
}
