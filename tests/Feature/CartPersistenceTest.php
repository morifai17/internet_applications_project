<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

final class CartPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_cart_persists_between_token_requests_and_checks_out(): void
    {
        $customer = User::factory()->create();
        $token = JWTAuth::fromUser($customer);
        $product = Product::factory()->create([
            'price' => '15.00',
            'stock_quantity' => 5,
        ]);

        $this->withToken($token)
            ->postJson(route('api.cart.store', $product), ['quantity' => 2])
            ->assertOk()
            ->assertJsonPath('data.item_count', 2);

        $this->withToken($token)
            ->getJson(route('api.cart.index'))
            ->assertOk()
            ->assertJsonPath('data.item_count', 2)
            ->assertJsonPath('data.subtotal', '30.00');

        $this->withToken($token)
            ->postJson(route('api.orders.store'))
            ->assertCreated()
            ->assertJsonPath('data.total_amount', '30.00');

        $this->assertSame(3, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertSame($customer->id, Order::query()->firstOrFail()->user_id);
    }

    public function test_api_cart_requires_a_token(): void
    {
        $product = Product::factory()->create();

        $this->postJson(route('api.cart.store', $product), ['quantity' => 1])
            ->assertUnauthorized();
    }

    public function test_guest_session_cart_is_merged_into_the_stored_cart_on_login(): void
    {
        $customer = User::factory()->create(['password' => 'StrongPass123!']);
        $kept = Product::factory()->create(['stock_quantity' => 10]);
        $capped = Product::factory()->create(['stock_quantity' => 3]);

        $this->postJson(route('cart.store', $kept), ['quantity' => 2])->assertOk();
        $this->postJson(route('cart.store', $capped), ['quantity' => 3])->assertOk();

        // The capped product's stock drops while the visitor is still a guest.
        $capped->update(['stock_quantity' => 1]);

        $this->postJson(route('login'), [
            'email' => $customer->email,
            'password' => 'StrongPass123!',
        ])->assertOk();

        $this->assertDatabaseHas('cart_items', ['product_id' => $kept->id, 'quantity' => 2]);
        $this->assertDatabaseHas('cart_items', ['product_id' => $capped->id, 'quantity' => 1]);

        $this->getJson(route('cart.index'))
            ->assertOk()
            ->assertJsonPath('data.item_count', 3);
    }

    public function test_api_logout_revokes_the_current_token(): void
    {
        $customer = User::factory()->create();
        $token = JWTAuth::fromUser($customer);

        $this->withToken($token)
            ->postJson(route('api.logout'))
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson(route('api.me'))
            ->assertUnauthorized();
    }
}
