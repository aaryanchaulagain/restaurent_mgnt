<?php

namespace App\Domain\Payments\Providers\Stripe;

use App\Domain\Payments\Contracts\RefundProvider;
use App\Domain\Payments\DTOs\RefundRequestData;
use App\Domain\Payments\DTOs\RefundResult;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Models\Payment;
use App\Support\PaymentErrorResponse;
use Stripe\Exception\ApiErrorException;

class StripeRefundProvider implements RefundProvider
{
    public function __construct(
        private readonly StripeClientFactory $clientFactory,
    ) {}

    public function createRefund(Payment $payment, int $amountCents, RefundRequestData $data): RefundResult
    {
        if ($amountCents <= 0) {
            throw new PaymentException(
                'REFUND_AMOUNT_INVALID',
                PaymentErrorResponse::messageForCode('REFUND_AMOUNT_INVALID'),
                422,
            );
        }

        $available = (int) $payment->amount_received_cents - (int) $payment->amount_refunded_cents;
        if ($payment->amount_received_cents <= 0) {
            $available = (int) $payment->amount_cents - (int) $payment->amount_refunded_cents;
        }

        if ($amountCents > $available) {
            throw new PaymentException(
                'REFUND_EXCEEDS_AVAILABLE_AMOUNT',
                PaymentErrorResponse::messageForCode('REFUND_EXCEEDS_AVAILABLE_AMOUNT'),
                422,
            );
        }

        if (! $payment->external_payment_intent_id && ! $payment->external_charge_id) {
            throw new PaymentException(
                'REFUND_NOT_ALLOWED',
                PaymentErrorResponse::messageForCode('REFUND_NOT_ALLOWED'),
                422,
            );
        }

        $params = [
            'amount' => $amountCents,
            'refund_application_fee' => $data->refundApplicationFee,
            'reverse_transfer' => $data->reverseTransfer,
            'metadata' => [
                'payment_public_id' => $payment->public_id,
                'reason_category' => $data->reasonCategory,
            ],
        ];

        if ($data->customerReason) {
            $params['reason'] = 'requested_by_customer';
            $params['metadata']['customer_reason'] = mb_substr($data->customerReason, 0, 500);
        }

        if ($payment->external_payment_intent_id) {
            $params['payment_intent'] = $payment->external_payment_intent_id;
        } else {
            $params['charge'] = $payment->external_charge_id;
        }

        try {
            $refund = $this->clientFactory->make()->refunds->create(
                $params,
                ['idempotency_key' => $data->idempotencyKey],
            );
        } catch (ApiErrorException $e) {
            throw new PaymentException(
                'REFUND_NOT_ALLOWED',
                PaymentErrorResponse::messageForCode('REFUND_NOT_ALLOWED'),
                502,
            );
        }

        return new RefundResult(
            externalRefundId: $refund->id,
            status: (string) $refund->status,
            amountCents: (int) $refund->amount,
        );
    }
}
