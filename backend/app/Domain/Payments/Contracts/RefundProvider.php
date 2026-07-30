<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\DTOs\RefundRequestData;
use App\Domain\Payments\DTOs\RefundResult;
use App\Models\Payment;

interface RefundProvider
{
    public function createRefund(Payment $payment, int $amountCents, RefundRequestData $data): RefundResult;
}
