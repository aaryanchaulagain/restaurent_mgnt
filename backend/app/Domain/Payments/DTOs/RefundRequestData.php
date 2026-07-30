<?php

namespace App\Domain\Payments\DTOs;

final readonly class RefundRequestData
{
    public function __construct(
        public string $reasonCategory,
        public ?string $customerReason,
        public bool $reverseTransfer,
        public bool $refundApplicationFee,
        public string $idempotencyKey,
    ) {}
}
