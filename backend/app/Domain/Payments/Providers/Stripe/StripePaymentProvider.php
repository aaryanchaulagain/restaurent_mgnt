<?php

namespace App\Domain\Payments\Providers\Stripe;

use App\Domain\Payments\Contracts\PaymentProvider;
use App\Domain\Payments\DTOs\PaymentIntentResult;
use App\Domain\Payments\Enums\ChargeStrategy;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Support\PaymentErrorResponse;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;

class StripePaymentProvider implements PaymentProvider
{
    public function __construct(
        private readonly StripeClientFactory $clientFactory,
    ) {}

    public function createPaymentIntent(Order $order, PaymentAttempt $attempt): PaymentIntentResult
    {
        $payment = $attempt->payment ?? $attempt->payment()->first();
        if (! $payment) {
            throw new PaymentException(
                'PAYMENT_INTENT_CREATION_FAILED',
                PaymentErrorResponse::messageForCode('PAYMENT_INTENT_CREATION_FAILED'),
                502,
            );
        }

        if ((int) $attempt->amount_cents !== (int) $order->total_cents) {
            throw new PaymentException(
                'PAYMENT_AMOUNT_MISMATCH',
                PaymentErrorResponse::messageForCode('PAYMENT_AMOUNT_MISMATCH'),
                422,
            );
        }

        $params = [
            'amount' => (int) $order->total_cents,
            'currency' => strtolower((string) ($order->currency ?: config('payments.currency', 'AUD'))),
            'metadata' => [
                'order_public_id' => $order->public_id,
                'payment_public_id' => $payment->public_id,
                'attempt_public_id' => $attempt->public_id,
                'attempt_number' => (string) $attempt->attempt_number,
            ],
            'automatic_payment_methods' => [
                'enabled' => true,
            ],
        ];

        if ($payment->transfer_group) {
            $params['transfer_group'] = $payment->transfer_group;
        }

        if ($payment->connected_account_id) {
            $strategy = (string) ($payment->metadata['strategy'] ?? config('payments.connect_charge_strategy'));

            if ($strategy === ChargeStrategy::DestinationCharge->value || $strategy === 'destination_charge') {
                $params['application_fee_amount'] = (int) $payment->platform_fee_cents;
                $params['transfer_data'] = [
                    'destination' => $payment->connected_account_id,
                ];
            }
        }

        try {
            $intent = $this->clientFactory->make()->paymentIntents->create(
                $params,
                ['idempotency_key' => $attempt->idempotency_key],
            );
        } catch (ApiErrorException $e) {
            throw new PaymentException(
                'PAYMENT_INTENT_CREATION_FAILED',
                PaymentErrorResponse::messageForCode('PAYMENT_INTENT_CREATION_FAILED'),
                502,
            );
        }

        return $this->toResult($intent);
    }

    public function retrievePaymentIntent(string $externalId): PaymentIntentResult
    {
        try {
            $intent = $this->clientFactory->make()->paymentIntents->retrieve($externalId);
        } catch (ApiErrorException $e) {
            throw new PaymentException(
                'PAYMENT_INTENT_CREATION_FAILED',
                PaymentErrorResponse::messageForCode('PAYMENT_INTENT_CREATION_FAILED'),
                502,
            );
        }

        return $this->toResult($intent);
    }

    public function cancelPaymentIntent(string $externalId): PaymentIntentResult
    {
        try {
            $intent = $this->clientFactory->make()->paymentIntents->cancel(
                $externalId,
                [],
                ['idempotency_key' => 'payment_intent:cancel:'.$externalId],
            );
        } catch (ApiErrorException $e) {
            throw new PaymentException(
                'PAYMENT_CANCELLED',
                PaymentErrorResponse::messageForCode('PAYMENT_CANCELLED'),
                422,
            );
        }

        return $this->toResult($intent);
    }

    private function toResult(PaymentIntent $intent): PaymentIntentResult
    {
        $chargeId = null;
        if (is_string($intent->latest_charge) && $intent->latest_charge !== '') {
            $chargeId = $intent->latest_charge;
        }

        return new PaymentIntentResult(
            externalId: $intent->id,
            clientSecret: $intent->client_secret,
            status: $intent->status,
            amountCents: (int) $intent->amount,
            currency: strtoupper((string) $intent->currency),
            chargeId: $chargeId,
            rawStatus: $intent->status,
        );
    }
}
