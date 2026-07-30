<?php

namespace App\Domain\Payments\Providers\Stripe;

use App\Domain\Payments\Exceptions\PaymentException;
use App\Support\PaymentErrorResponse;
use Stripe\StripeClient;

class StripeClientFactory
{
    public function make(): StripeClient
    {
        $secret = config('payments.stripe.secret_key');

        if (! is_string($secret) || $secret === '') {
            throw new PaymentException(
                'PAYMENT_CONFIGURATION_MISSING',
                PaymentErrorResponse::messageForCode('PAYMENT_CONFIGURATION_MISSING'),
                503,
            );
        }

        return new StripeClient([
            'api_key' => $secret,
            'stripe_version' => config('payments.stripe.api_version', '2024-11-20.acacia'),
        ]);
    }
}
