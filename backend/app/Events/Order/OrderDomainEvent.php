<?php

namespace App\Events\Order;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class OrderDomainEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $orderPublicId,
        public readonly string $orderNumber,
        public readonly int $restaurantId,
        public readonly ?int $customerId,
        public readonly ?string $oldStatus,
        public readonly string $newStatus,
        public readonly string $occurredAt,
    ) {}
}
