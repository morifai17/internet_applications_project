<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\EmptyCartException;
use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Notifications\NewOrderReceived;
use App\Notifications\OrderPlaced;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Turns the customer's cart into an order.
 *
 * Prices, totals and availability are recalculated from locked database rows;
 * nothing supplied by the browser is trusted.
 */
final class CheckoutService
{
    public function __construct(private readonly CartService $cart) {}

    public function placeOrder(User $user): Order
    {
        $cart = $this->cart->raw();

        if ($cart === []) {
            throw new EmptyCartException;
        }

        $order = DB::transaction(fn (): Order => $this->createOrder($user, $cart));

        $this->cart->clear();

        $user->notify(new OrderPlaced($order));
        Notification::send(User::query()->admins()->get(), new NewOrderReceived($order));

        return $order;
    }

    /**
     * @param  array<int, int>  $cart
     */
    private function createOrder(User $user, array $cart): Order
    {
        $products = Product::query()
            ->whereIn('id', array_keys($cart))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

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

            if ($product->stock_quantity < $quantity) {
                throw new InsufficientStockException(
                    $product->title,
                    $product->stock_quantity,
                    $quantity,
                );
            }

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

            $line['product']->stock_quantity -= $line['quantity'];
            $line['product']->save();
        }

        return $order->load(['items.product', 'user']);
    }
}
