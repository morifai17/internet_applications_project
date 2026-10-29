<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

final class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // Two aggregate queries instead of four round trips.
        $productStats = Product::query()
            ->selectRaw('COUNT(*) AS total_products, COALESCE(SUM(stock_quantity), 0) AS stock_units')
            ->first();

        $orderStats = Order::query()
            ->selectRaw(
                'COUNT(*) AS total_orders, COALESCE(SUM(CASE WHEN status = ? THEN total_amount ELSE 0 END), 0) AS total_sales',
                [OrderStatus::Completed->value],
            )
            ->first();

        $recentOrders = Order::query()
            ->with('user')
            ->latest()
            ->limit(6)
            ->get();

        return response()->json([
            'data' => [
                'total_products' => (int) $productStats->total_products,
                'total_orders' => (int) $orderStats->total_orders,
                // Sales only include completed orders.
                'total_sales' => number_format((float) $orderStats->total_sales, 2, '.', ''),
                'stock_units' => (int) $productStats->stock_units,
                'currency' => config('store.currency'),
            ],
            'recent_orders' => OrderResource::collection($recentOrders)->resolve(),
        ]);
    }
}
