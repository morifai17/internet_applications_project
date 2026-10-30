<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderStatusChanged;
use App\Modules\Orders\Exceptions\InvalidOrderTransitionException;
use App\Modules\Orders\Models\Order;
use App\Modules\Products\Services\ProductService;
use Illuminate\Support\Facades\DB;

/**
 * Owns order status transitions and the stock restoration that must happen
 * exactly once when an order is cancelled.
 */
final class OrderService
{
    public function __construct(private readonly ProductService $products) {}

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

        // Announce only after the transaction committed and only on a real change.
        if ($previousStatus !== null) {
            OrderStatusChanged::dispatch($updated, $previousStatus);
        }

        return $updated;
    }

    /**
     * Return every ordered unit to stock. The caller must already hold a lock
     * on the order row so two cancellations cannot race each other.
     */
    private function restoreStock(Order $order): void
    {
        $quantities = $order->items()
            ->get(['product_id', 'quantity'])
            ->groupBy('product_id')
            ->map(fn ($productItems): int => (int) $productItems->sum('quantity'))
            ->all();

        $this->products->increaseStock($quantities);
    }
}
