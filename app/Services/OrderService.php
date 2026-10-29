<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Order;
use App\Models\Product;
use App\Notifications\OrderStatusChanged;
use Illuminate\Support\Facades\DB;

/**
 * Owns order status transitions and the stock restoration that must happen
 * exactly once when an order is cancelled.
 */
final class OrderService
{
    public function updateStatus(Order $order, OrderStatus $newStatus): Order
    {
        $previousStatus = null;

        $updated = DB::transaction(function () use ($order, $newStatus, &$previousStatus): Order {
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->findOrFail($order->getKey());

            // Re-submitting the current status is a harmless no-op. Because
            // cancelled is terminal, stock can never be restored twice.
            if ($lockedOrder->status === $newStatus) {
                return $lockedOrder;
            }

            if (! $lockedOrder->status->canTransitionTo($newStatus)) {
                throw new InvalidOrderTransitionException($lockedOrder->status, $newStatus);
            }

            if ($newStatus === OrderStatus::Cancelled) {
                $this->restoreStock($lockedOrder);
            }

            $previousStatus = $lockedOrder->status;
            $lockedOrder->update(['status' => $newStatus]);

            return $lockedOrder->refresh();
        });

        // Notify only after the transaction committed and only on a real change.
        if ($previousStatus !== null) {
            $updated->user->notify(new OrderStatusChanged($updated, $previousStatus));
        }

        return $updated;
    }

    /**
     * Return every ordered unit to stock. The caller must already hold a lock
     * on the order row so two cancellations cannot race each other.
     */
    private function restoreStock(Order $order): void
    {
        $items = $order->items()->get(['product_id', 'quantity']);

        if ($items->isEmpty()) {
            return;
        }

        // Lock all affected products in a deterministic order to avoid
        // deadlocks with concurrent checkouts.
        $productIds = $items->pluck('product_id')->unique()->sort()->values();

        Product::query()
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($items->groupBy('product_id') as $productId => $productItems) {
            Product::query()
                ->whereKey($productId)
                ->increment('stock_quantity', (int) $productItems->sum('quantity'));
        }
    }
}
