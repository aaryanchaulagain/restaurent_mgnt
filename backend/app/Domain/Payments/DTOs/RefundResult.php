<?php

namespace App\Domain\Payments\DTOs;

final readonly class RefundResult
{
    public function __construct(
        public string $externalRefundId,
        public string $status,
        public int $amountCents,
    ) {}
}
