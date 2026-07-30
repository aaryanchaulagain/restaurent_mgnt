<?php

namespace App\Domain\Payments\DTOs;

final readonly class PaymentIntentResult
{
    public function __construct(
        public string $externalId,
        public ?string $clientSecret,
        public string $status,
        public int $amountCents,
        public string $currency,
        public ?string $chargeId,
        public string $rawStatus,
    ) {}
}
