<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\AddToCartRequest;
use App\Http\Requests\Shop\UpdateCartRequest;
use App\Models\Product;
use App\Services\CartService;
use App\Support\CartPayload;
use Illuminate\Http\JsonResponse;

final class CartController extends Controller
{
    public function index(CartService $cart): JsonResponse
    {
        return response()->json(['data' => CartPayload::from($cart)]);
    }

    public function store(
        AddToCartRequest $request,
        Product $product,
        CartService $cart,
    ): JsonResponse {
        $cart->add($product, $request->integer('quantity'));

        return response()->json([
            'message' => "\"{$product->title}\" was added to your cart.",
            'data' => CartPayload::from($cart),
        ]);
    }

    public function update(
        UpdateCartRequest $request,
        Product $product,
        CartService $cart,
    ): JsonResponse {
        $cart->updateQuantity($product, $request->integer('quantity'));

        return response()->json([
            'message' => 'Your cart was updated.',
            'data' => CartPayload::from($cart),
        ]);
    }

    public function destroy(Product $product, CartService $cart): JsonResponse
    {
        $cart->remove($product->id);

        return response()->json([
            'message' => "\"{$product->title}\" was removed from your cart.",
            'data' => CartPayload::from($cart),
        ]);
    }

    public function clear(CartService $cart): JsonResponse
    {
        $cart->clear();

        return response()->json([
            'message' => 'Your cart is empty.',
            'data' => CartPayload::from($cart),
        ]);
    }
}
