<?php

namespace App\Services\Order;

use App\Events\Order\OrderAccepted;
use App\Events\Order\OrderCancelled;
use App\Events\Order\OrderCompleted;
use App\Events\Order\OrderDomainEvent;
use App\Events\Order\OrderExpired;
use App\Events\Order\OrderPlaced;
use App\Events\Order\OrderPreparing;
use App\Events\Order\OrderReady;
use App\Events\Order\OrderRejected;
use App\Models\Order;

class OrderEventDispatcher
{
    public function placed(Order $order): void
    {
        event(new OrderPlaced(
            $order->public_id,
            $order->order_number,
            $order->restaurant_id,
            $order->customer_id,
            $order->placed_at?->toIso8601String() ?? now()->toIso8601String(),
        ));
    }

    public function statusChanged(Order $order, ?string $oldStatus, string $newStatus): void
    {
        $event = $this->eventForStatus($order, $oldStatus, $newStatus);
        if ($event) {
            event($event);
        }
    }

    private function eventForStatus(Order $order, ?string $oldStatus, string $newStatus): ?OrderDomainEvent
    {
        $at = now()->toIso8601String();
        $args = [$order->public_id, $order->order_number, $order->restaurant_id, $order->customer_id, $oldStatus, $newStatus, $at];

        return match ($newStatus) {
            'accepted' => new OrderAccepted(...$args),
            'rejected' => new OrderRejected(...$args),
            'preparing' => new OrderPreparing(...$args),
            'ready_for_pickup' => new OrderReady(...$args),
            'completed_pickup' => new OrderCompleted(...$args),
            'cancelled' => new OrderCancelled(...$args),
            'expired' => new OrderExpired(...$args),
            default => null,
        };
    }
}
