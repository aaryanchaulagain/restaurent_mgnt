<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\DTOs\PaymentIntentResult;
use App\Models\Order;
use App\Models\PaymentAttempt;

interface PaymentProvider
{
    public function createPaymentIntent(Order $order, PaymentAttempt $attempt): PaymentIntentResult;

    public function retrievePaymentIntent(string $externalId): PaymentIntentResult;

    public function cancelPaymentIntent(string $externalId): PaymentIntentResult;
}
