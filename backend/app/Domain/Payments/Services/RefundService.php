<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Contracts\RefundProvider;
use App\Domain\Payments\DTOs\RefundRequestData;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Support\PaymentErrorResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RefundService
{
    public function __construct(
        private readonly RefundProvider $provider,
        private readonly PaymentFundsFlowService $fundsFlow,
        private readonly AuditLogger $audit,
    ) {}

    public function requestRefund(
        Payment $payment,
        User $actor,
        int $amountCents,
        string $reasonCategory,
        ?string $customerReason,
        ?string $internalNote,
        string $idempotencyKey,
    ): Refund {
        return DB::transaction(function () use (
            $payment,
            $actor,
            $amountCents,
            $reasonCategory,
            $customerReason,
            $internalNote,
            $idempotencyKey,
        ) {
            $existing = Refund::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            /** @var Payment $payment */
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $this->assertRefundable($payment, $amountCents);

            $flags = $this->fundsFlow->refundFlagsForPayment($payment);

            $refund = Refund::query()->create([
                'public_id' => (string) Str::uuid(),
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
                'restaurant_id' => $payment->restaurant_id,
                'requested_by_user_id' => $actor->id,
                'approved_by_user_id' => $actor->id,
                'provider' => 'stripe',
                'status' => RefundStatus::Requested->value,
                'amount_cents' => $amountCents,
                'currency' => $payment->currency,
                'reason_category' => $reasonCategory,
                'customer_reason' => $customerReason,
                'internal_note' => $internalNote,
                'refund_application_fee' => $flags['refund_application_fee'],
                'reverse_transfer' => $flags['reverse_transfer'],
                'idempotency_key' => $idempotencyKey,
                'requested_at' => now(),
            ]);

            $result = $this->provider->createRefund(
                $payment,
                $amountCents,
                new RefundRequestData(
                    reasonCategory: $reasonCategory,
                    customerReason: $customerReason,
                    reverseTransfer: $flags['reverse_transfer'],
                    refundApplicationFee: $flags['refund_application_fee'],
                    idempotencyKey: $idempotencyKey,
                ),
            );

            $refund->fill([
                'external_refund_id' => $result->externalRefundId,
                'status' => RefundStatus::Processing->value,
            ])->save();

            $this->audit->log(
                action: 'payment.refund_requested',
                actor: $actor,
                auditable: $refund,
                restaurantId: $payment->restaurant_id,
                newValues: [
                    'refund_public_id' => $refund->public_id,
                    'payment_public_id' => $payment->public_id,
                    'amount_cents' => $amountCents,
                    'reason_category' => $reasonCategory,
                    'external_refund_id' => $result->externalRefundId,
                ],
            );

            return $refund->fresh();
        });
    }

    /**
     * Restaurant-initiated refund request — local record only (no Stripe call).
     */
    public function createLocalRequest(
        Payment $payment,
        User $actor,
        int $amountCents,
        string $reasonCategory,
        ?string $customerReason,
        ?string $internalNote,
        string $idempotencyKey,
    ): Refund {
        return DB::transaction(function () use (
            $payment,
            $actor,
            $amountCents,
            $reasonCategory,
            $customerReason,
            $internalNote,
            $idempotencyKey,
        ) {
            $existing = Refund::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            /** @var Payment $payment */
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $this->assertRefundable($payment, $amountCents);

            $flags = $this->fundsFlow->refundFlagsForPayment($payment);

            $refund = Refund::query()->create([
                'public_id' => (string) Str::uuid(),
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
                'restaurant_id' => $payment->restaurant_id,
                'requested_by_user_id' => $actor->id,
                'provider' => 'stripe',
                'status' => RefundStatus::Requested->value,
                'amount_cents' => $amountCents,
                'currency' => $payment->currency,
                'reason_category' => $reasonCategory,
                'customer_reason' => $customerReason,
                'internal_note' => $internalNote,
                'refund_application_fee' => $flags['refund_application_fee'],
                'reverse_transfer' => $flags['reverse_transfer'],
                'idempotency_key' => $idempotencyKey,
                'requested_at' => now(),
            ]);

            $this->audit->log(
                action: 'payment.refund_requested',
                actor: $actor,
                auditable: $refund,
                restaurantId: $payment->restaurant_id,
                newValues: [
                    'refund_public_id' => $refund->public_id,
                    'payment_public_id' => $payment->public_id,
                    'amount_cents' => $amountCents,
                    'reason_category' => $reasonCategory,
                    'local_only' => true,
                ],
            );

            return $refund->fresh();
        });
    }

    private function assertRefundable(Payment $payment, int $amountCents): void
    {
        if (! in_array($payment->status, [
            PaymentStatus::Paid->value,
            PaymentStatus::PartiallyRefunded->value,
            PaymentStatus::Disputed->value,
        ], true)) {
            throw new PaymentException(
                'REFUND_NOT_ALLOWED',
                PaymentErrorResponse::messageForCode('REFUND_NOT_ALLOWED'),
                422,
            );
        }

        if ($amountCents <= 0) {
            throw new PaymentException(
                'REFUND_AMOUNT_INVALID',
                PaymentErrorResponse::messageForCode('REFUND_AMOUNT_INVALID'),
                422,
            );
        }

        $received = (int) $payment->amount_received_cents > 0
            ? (int) $payment->amount_received_cents
            : (int) $payment->amount_cents;
        $available = $received - (int) $payment->amount_refunded_cents;

        if ($amountCents > $available) {
            throw new PaymentException(
                'REFUND_EXCEEDS_AVAILABLE_AMOUNT',
                PaymentErrorResponse::messageForCode('REFUND_EXCEEDS_AVAILABLE_AMOUNT'),
                422,
            );
        }
    }
}
