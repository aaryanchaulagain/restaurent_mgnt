<?php

namespace App\Events\Order;

class OrderPlaced extends OrderDomainEvent
{
    public function __construct(
        string $orderPublicId,
        string $orderNumber,
        int $restaurantId,
        ?int $customerId,
        string $occurredAt,
    ) {
        parent::__construct($orderPublicId, $orderNumber, $restaurantId, $customerId, null, 'awaiting_restaurant', $occurredAt);
    }
}
