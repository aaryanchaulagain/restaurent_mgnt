<?php

namespace App\Services\Order;

class OrderStatusMachine
{
    private const TRANSITIONS = [
        'pending_payment' => ['awaiting_restaurant', 'payment_failed', 'cancelled', 'expired'],
        'payment_failed' => ['pending_payment', 'cancelled', 'expired'],
        'awaiting_restaurant' => ['accepted', 'rejected', 'cancelled', 'expired'],
        'accepted' => ['preparing', 'cancelled'],
        'preparing' => ['ready_for_pickup', 'cancelled'],
        'ready_for_pickup' => ['completed_pickup'],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function allowedTransitions(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, ['completed_pickup', 'rejected', 'cancelled', 'expired', 'payment_failed'], true);
    }

    public static function customerCancellableStatuses(): array
    {
        return config('order.customer_cancellable_statuses', ['awaiting_restaurant']);
    }
}
