<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        $products = Product::query()
            ->with('category')
            ->search($filters['q'] ?? null)
            ->inCategory($filters['category'] ?? null)
            ->latest()
            ->paginate(9)
            ->withQueryString();

        return ProductResource::collection($products)->response();
    }

    public function show(Product $product): JsonResponse
    {
        $product->load('category');

        $related = Product::query()
            ->with('category')
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->latest()
            ->limit(3)
            ->get();

        return response()->json([
            'data' => (new ProductResource($product))->resolve(),
            'related' => ProductResource::collection($related)->resolve(),
        ]);
    }
}
