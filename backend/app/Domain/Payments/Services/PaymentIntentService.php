<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Contracts\PaymentProvider;
use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Services\Auth\AuditLogger;
use App\Support\PaymentErrorResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PaymentIntentService
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly PaymentFundsFlowService $fundsFlow,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{payment: Payment, client_secret: string|null, publishable_key: string|null}
     */
    public function createForOrder(Order $order): array
    {
        return DB::transaction(function () use ($order) {
            /** @var Order $order */
            $order = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->with('restaurant.paymentAccount')
                ->firstOrFail();

            if ($order->payment_status === PaymentStatus::Paid->value) {
                throw new PaymentException(
                    'PAYMENT_ALREADY_PAID',
                    PaymentErrorResponse::messageForCode('PAYMENT_ALREADY_PAID'),
                    409,
                );
            }

            $paidPayment = Payment::query()
                ->where('order_id', $order->id)
                ->where('status', PaymentStatus::Paid->value)
                ->exists();

            if ($paidPayment) {
                throw new PaymentException(
                    'PAYMENT_ALREADY_PAID',
                    PaymentErrorResponse::messageForCode('PAYMENT_ALREADY_PAID'),
                    409,
                );
            }

            $flow = $this->fundsFlow->resolveForOrder($order);

            $payment = Payment::query()
                ->where('order_id', $order->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                $payment = Payment::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'order_id' => $order->id,
                    'restaurant_id' => $order->restaurant_id,
                    'customer_id' => $order->customer_id,
                    'provider' => 'stripe',
                    'payment_method_type' => 'card',
                    'status' => PaymentStatus::Pending->value,
                    'currency' => $order->currency ?: config('payments.currency', 'AUD'),
                    'amount_cents' => (int) $order->total_cents,
                    'amount_received_cents' => 0,
                    'amount_refunded_cents' => 0,
                    'platform_fee_cents' => $flow['platform_fee_cents'],
                    'restaurant_share_cents' => $flow['restaurant_share_cents'],
                    'connected_account_id' => $flow['connected_account_id'],
                    'transfer_group' => 'order_'.$order->public_id,
                    'metadata' => [
                        'ownership_type' => $flow['ownership_type'],
                        'strategy' => $flow['strategy'],
                    ],
                ]);
            } else {
                if ((int) $payment->amount_cents !== (int) $order->total_cents) {
                    throw new PaymentException(
                        'PAYMENT_AMOUNT_MISMATCH',
                        PaymentErrorResponse::messageForCode('PAYMENT_AMOUNT_MISMATCH'),
                        422,
                    );
                }

                $payment->fill([
                    'platform_fee_cents' => $flow['platform_fee_cents'],
                    'restaurant_share_cents' => $flow['restaurant_share_cents'],
                    'connected_account_id' => $flow['connected_account_id'],
                    'status' => PaymentStatus::Pending->value,
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'ownership_type' => $flow['ownership_type'],
                        'strategy' => $flow['strategy'],
                    ]),
                ])->save();
            }

            $maxAttempts = (int) config('payments.max_retry_attempts', 5);
            $attemptCount = PaymentAttempt::query()->where('payment_id', $payment->id)->count();

            if ($attemptCount >= $maxAttempts) {
                throw new PaymentException(
                    'PAYMENT_ATTEMPT_LIMIT_REACHED',
                    PaymentErrorResponse::messageForCode('PAYMENT_ATTEMPT_LIMIT_REACHED'),
                    422,
                );
            }

            $attemptNumber = $attemptCount + 1;
            $idempotencyKey = 'payment_intent:create:'.$order->public_id.':'.$attemptNumber;

            $payload = [
                'order_public_id' => $order->public_id,
                'amount_cents' => (int) $order->total_cents,
                'currency' => $order->currency,
                'platform_fee_cents' => $flow['platform_fee_cents'],
                'connected_account_id' => $flow['connected_account_id'],
                'strategy' => $flow['strategy'],
                'attempt_number' => $attemptNumber,
            ];

            $attempt = PaymentAttempt::query()->create([
                'public_id' => (string) Str::uuid(),
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'attempt_number' => $attemptNumber,
                'idempotency_key' => $idempotencyKey,
                'request_payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'status' => PaymentAttemptStatus::Pending->value,
                'amount_cents' => (int) $order->total_cents,
                'currency' => $order->currency ?: config('payments.currency', 'AUD'),
                'started_at' => now(),
                'expires_at' => now()->addMinutes((int) config('payments.pending_expiry_minutes', 30)),
            ]);

            $attempt->setRelation('payment', $payment);
            $payment->setRelation('order', $order);

            try {
                $result = $this->provider->createPaymentIntent($order, $attempt);
            } catch (PaymentException $e) {
                $attempt->fill([
                    'status' => PaymentAttemptStatus::Failed->value,
                    'failure_code' => $e->errorCode,
                    'failure_message' => $e->getMessage(),
                    'completed_at' => now(),
                ])->save();

                $payment->fill([
                    'status' => PaymentStatus::Failed->value,
                    'failed_at' => now(),
                    'last_error_code' => $e->errorCode,
                    'last_error_message' => $e->getMessage(),
                ])->save();

                throw $e;
            } catch (Throwable $e) {
                $attempt->fill([
                    'status' => PaymentAttemptStatus::Failed->value,
                    'failure_code' => 'PAYMENT_INTENT_CREATION_FAILED',
                    'failure_message' => 'Provider error',
                    'completed_at' => now(),
                ])->save();

                $payment->fill([
                    'status' => PaymentStatus::Failed->value,
                    'failed_at' => now(),
                    'last_error_code' => 'PAYMENT_INTENT_CREATION_FAILED',
                    'last_error_message' => 'Provider error',
                ])->save();

                throw new PaymentException(
                    'PAYMENT_INTENT_CREATION_FAILED',
                    PaymentErrorResponse::messageForCode('PAYMENT_INTENT_CREATION_FAILED'),
                    502,
                );
            }

            $mappedPaymentStatus = $this->mapProviderStatusToPayment($result->rawStatus);
            $mappedAttemptStatus = $this->mapProviderStatusToAttempt($result->rawStatus);

            $attempt->fill([
                'external_payment_intent_id' => $result->externalId,
                'client_secret_encrypted' => $result->clientSecret
                    ? Crypt::encryptString($result->clientSecret)
                    : null,
                'status' => $mappedAttemptStatus,
                'requires_action' => $mappedAttemptStatus === PaymentAttemptStatus::RequiresAction->value,
            ])->save();

            $payment->fill([
                'external_payment_intent_id' => $result->externalId,
                'external_charge_id' => $result->chargeId,
                'status' => $mappedPaymentStatus,
                'provider_created_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
                'failed_at' => null,
            ])->save();

            $this->audit->log(
                action: 'payment.intent_created',
                auditable: $payment,
                restaurantId: $payment->restaurant_id,
                newValues: [
                    'payment_public_id' => $payment->public_id,
                    'order_public_id' => $order->public_id,
                    'attempt_number' => $attemptNumber,
                    'amount_cents' => $payment->amount_cents,
                    'external_payment_intent_id' => $result->externalId,
                    'strategy' => $flow['strategy'],
                ],
                metadata: [
                    'ownership_type' => $flow['ownership_type'],
                ],
            );

            return [
                'payment' => $payment->fresh(['attempts']),
                'client_secret' => $result->clientSecret,
                'publishable_key' => config('payments.stripe.publishable_key'),
            ];
        });
    }

    private function mapProviderStatusToPayment(string $rawStatus): string
    {
        return match ($rawStatus) {
            'requires_action' => PaymentStatus::RequiresAction->value,
            'processing' => PaymentStatus::Processing->value,
            'canceled', 'cancelled' => PaymentStatus::Cancelled->value,
            // Never mark paid from provider create/retrieve in this service — webhooks own paid.
            'succeeded' => PaymentStatus::Processing->value,
            default => PaymentStatus::Pending->value,
        };
    }

    private function mapProviderStatusToAttempt(string $rawStatus): string
    {
        return match ($rawStatus) {
            'requires_action' => PaymentAttemptStatus::RequiresAction->value,
            'processing' => PaymentAttemptStatus::Processing->value,
            'canceled', 'cancelled' => PaymentAttemptStatus::Cancelled->value,
            'succeeded' => PaymentAttemptStatus::Succeeded->value,
            default => PaymentAttemptStatus::Pending->value,
        };
    }
}
