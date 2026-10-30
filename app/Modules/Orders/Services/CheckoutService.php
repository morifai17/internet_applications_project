<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderPlaced;
use App\Modules\Orders\Exceptions\EmptyCartException;
use App\Modules\Orders\Models\Order;
use App\Modules\Products\Services\ProductService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns the customer's cart into an order.
 *
 * Prices, totals and availability are recalculated from locked database rows;
 * nothing supplied by the browser is trusted. Stock is changed only through
 * the Products module, and notifications are left to listeners of the
 * OrderPlaced event.
 */
final class CheckoutService
{
    public function __construct(
        private readonly CartService $cart,
        private readonly ProductService $products,
    ) {}

    public function placeOrder(User $user): Order
    {
        $cart = $this->cart->raw();

        if ($cart === []) {
            throw new EmptyCartException;
        }

        $order = DB::transaction(fn (): Order => $this->createOrder($user, $cart));

        $this->cart->clear();

        OrderPlaced::dispatch($order);

        return $order;
    }

    /**
     * @param  array<int, int>  $cart
     */
    private function createOrder(User $user, array $cart): Order
    {
        $products = $this->products->lockForStockChange(array_keys($cart));

        $lines = [];
        $totalCents = 0;

        foreach ($cart as $productId => $quantity) {
            $product = $products->get($productId);

            if ($product === null) {
                throw new EmptyCartException(
                    'A product in your cart is no longer available. Please review your cart.',
                );
            }

            if ($quantity < 1) {
                continue;
            }

            // Throws InsufficientStockException, rolling the order back.
            $this->products->decreaseStock($product, $quantity);

            $unitPriceCents = (int) round((float) $product->price * 100);
            $totalCents += $unitPriceCents * $quantity;

            $lines[] = [
                'product' => $product,
                'quantity' => $quantity,
                'unit_price' => number_format($unitPriceCents / 100, 2, '.', ''),
            ];
        }

        if ($lines === []) {
            throw new EmptyCartException;
        }

        $order = Order::query()->create([
            'user_id' => $user->id,
            'total_amount' => number_format($totalCents / 100, 2, '.', ''),
            'status' => OrderStatus::Pending,
        ]);

        foreach ($lines as $line) {
            $order->items()->create([
                'product_id' => $line['product']->id,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
            ]);
        }

        return $order->load(['items.product', 'user']);
    }
}
