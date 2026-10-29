<?php

namespace App\Support;

use App\Http\Resources\ProductResource;
use App\Services\CartService;

/**
 * Serializes the cart for JSON responses.
 */
final class CartPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function from(CartService $cart): array
    {
        $items = $cart->items();

        return [
            'items' => array_map(
                fn (array $line): array => [
                    'product' => (new ProductResource($line['product']))->resolve(),
                    'quantity' => $line['quantity'],
                    'line_total' => number_format($line['line_total'], 2, '.', ''),
                ],
                $items,
            ),
            'item_count' => array_sum(array_column($items, 'quantity')),
            'subtotal' => number_format($cart->subtotal(), 2, '.', ''),
            'currency' => config('store.currency'),
        ];
    }
}
