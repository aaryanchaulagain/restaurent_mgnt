<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Enums\WebhookProcessingStatus;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentDispute;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Models\RestaurantPaymentAccount;
use App\Services\Auth\AuditLogger;
use App\Services\Order\OrderEventDispatcher;
use App\Services\Order\OrderTransitionService;
use App\Support\PaymentErrorResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\Event;

class StripeEventProcessor
{
    public function __construct(
        private readonly OrderTransitionService $transitions,
        private readonly OrderEventDispatcher $orderEvents,
        private readonly AuditLogger $audit,
    ) {}

    public function process(Event $event, PaymentWebhookEvent $webhookEvent): void
    {
        $object = $this->objectArray($event);

        match ($event->type) {
            'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($object, $webhookEvent),
            'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($object, $webhookEvent),
            'payment_intent.processing' => $this->handlePaymentIntentProcessing($object, $webhookEvent),
            'payment_intent.canceled' => $this->handlePaymentIntentCanceled($object, $webhookEvent),
            'charge.refunded' => $this->handleChargeRefunded($object, $webhookEvent),
            'refund.created', 'refund.updated' => $this->handleRefundUpdated($object, $webhookEvent),
            'refund.failed' => $this->handleRefundFailed($object, $webhookEvent),
            'charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed' => $this->handleDispute($object, $webhookEvent),
            'account.updated' => $this->handleAccountUpdated($object, $webhookEvent),
            default => $this->markIgnored($webhookEvent),
        };
    }

    /**
     * Apply a retrieved PaymentIntent status during reconciliation (idempotent).
     *
     * @param  array<string, mixed>  $intent
     */
    public function applyPaymentIntentStatus(Payment $payment, array $intent): void
    {
        $status = (string) ($intent['status'] ?? '');
        $webhookStub = new PaymentWebhookEvent;

        match ($status) {
            'succeeded' => $this->handlePaymentIntentSucceeded($intent, $webhookStub),
            'processing' => $this->handlePaymentIntentProcessing($intent, $webhookStub),
            'canceled' => $this->handlePaymentIntentCanceled($intent, $webhookStub),
            'requires_payment_method', 'requires_confirmation' => null,
            default => null,
        };

        if (in_array($status, ['requires_payment_method', 'canceled'], true)
            && ($intent['last_payment_error'] ?? null)) {
            $this->handlePaymentIntentFailed($intent, $webhookStub);
        }
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function handlePaymentIntentSucceeded(array $intent, PaymentWebhookEvent $webhookEvent): void
    {
        DB::transaction(function () use ($intent, $webhookEvent) {
            $payment = $this->lockPaymentByIntent($intent['id'] ?? null);
            if (! $payment) {
                $this->markIgnored($webhookEvent);

                return;
            }

            $this->linkWebhook($webhookEvent, $payment);

            $order = $payment->order()->lockForUpdate()->firstOrFail();

            $amount = (int) ($intent['amount_received'] ?? $intent['amount'] ?? 0);
            $currency = strtoupper((string) ($intent['currency'] ?? ''));

            if ($amount !== (int) $order->total_cents && $amount !== (int) $payment->amount_cents) {
                throw new PaymentException(
                    'PAYMENT_AMOUNT_MISMATCH',
                    PaymentErrorResponse::messageForCode('PAYMENT_AMOUNT_MISMATCH'),
                    422,
                );
            }

            if ($currency !== '' && strtoupper((string) $order->currency) !== $currency) {
                throw new PaymentException(
                    'PAYMENT_CURRENCY_MISMATCH',
                    PaymentErrorResponse::messageForCode('PAYMENT_CURRENCY_MISMATCH'),
                    422,
                );
            }

            $alreadyPaid = $payment->status === PaymentStatus::Paid->value;

            $chargeId = null;
            if (is_string($intent['latest_charge'] ?? null)) {
                $chargeId = $intent['latest_charge'];
            } elseif (is_array($intent['latest_charge'] ?? null)) {
                $chargeId = $intent['latest_charge']['id'] ?? null;
            }

            if (! $alreadyPaid) {
                $payment->fill([
                    'status' => PaymentStatus::Paid->value,
                    'amount_received_cents' => $amount > 0 ? $amount : (int) $payment->amount_cents,
                    'external_charge_id' => $chargeId ?: $payment->external_charge_id,
                    'paid_at' => now(),
                    'failed_at' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ])->save();

                PaymentAttempt::query()
                    ->where('payment_id', $payment->id)
                    ->where('external_payment_intent_id', $payment->external_payment_intent_id)
                    ->orderByDesc('id')
                    ->limit(1)
                    ->update([
                        'status' => PaymentAttemptStatus::Succeeded->value,
                        'completed_at' => now(),
                        'requires_action' => false,
                    ]);
            } elseif ($chargeId && ! $payment->external_charge_id) {
                $payment->fill(['external_charge_id' => $chargeId])->save();
            }

            $order->payment_status = PaymentStatus::Paid->value;
            $order->payment_reference = $payment->external_payment_intent_id;
            $order->save();

            $shouldPlace = false;
            if ($order->status === 'pending_payment') {
                $this->transitions->transition($order, 'awaiting_restaurant', null, 'system', 'Payment succeeded');
                $shouldPlace = true;
            }

            if (! $alreadyPaid) {
                $this->audit->log(
                    action: 'payment.succeeded',
                    auditable: $payment,
                    restaurantId: $payment->restaurant_id,
                    newValues: [
                        'payment_public_id' => $payment->public_id,
                        'order_public_id' => $order->public_id,
                        'amount_received_cents' => $payment->amount_received_cents,
                        'external_charge_id' => $payment->external_charge_id,
                    ],
                );
            }

            if ($shouldPlace && ! $alreadyPaid) {
                $orderId = $order->id;
                DB::afterCommit(function () use ($orderId) {
                    $fresh = \App\Models\Order::query()->with(['items'])->find($orderId);
                    if ($fresh) {
                        $this->orderEvents->placed($fresh);
                    }
                });
            }
        });
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function handlePaymentIntentFailed(array $intent, PaymentWebhookEvent $webhookEvent): void
    {
        DB::transaction(function () use ($intent, $webhookEvent) {
            $payment = $this->lockPaymentByIntent($intent['id'] ?? null);
            if (! $payment) {
                $this->markIgnored($webhookEvent);

                return;
            }

            $this->linkWebhook($webhookEvent, $payment);

            if ($payment->status === PaymentStatus::Paid->value) {
                return;
            }

            $order = $payment->order()->lockForUpdate()->firstOrFail();
            $error = $intent['last_payment_error'] ?? [];
            $code = is_array($error) ? ($error['code'] ?? 'PAYMENT_FAILED') : 'PAYMENT_FAILED';
            $message = PaymentErrorResponse::messageForCode('PAYMENT_FAILED');

            $payment->fill([
                'status' => PaymentStatus::Failed->value,
                'failed_at' => now(),
                'last_error_code' => is_string($code) ? $code : 'PAYMENT_FAILED',
                'last_error_message' => $message,
            ])->save();

            PaymentAttempt::query()
                ->where('payment_id', $payment->id)
                ->where('external_payment_intent_id', $payment->external_payment_intent_id)
                ->orderByDesc('id')
                ->limit(1)
                ->update([
                    'status' => PaymentAttemptStatus::Failed->value,
                    'failure_code' => is_string($code) ? $code : 'PAYMENT_FAILED',
                    'failure_message' => $message,
                    'completed_at' => now(),
                ]);

            $order->payment_status = PaymentStatus::Failed->value;
            $order->save();

            if ($order->status === 'pending_payment') {
                $this->transitions->transition($order, 'payment_failed', null, 'system', 'Payment failed');
            }

            $this->audit->log(
                action: 'payment.failed',
                auditable: $payment,
                restaurantId: $payment->restaurant_id,
                newValues: [
                    'payment_public_id' => $payment->public_id,
                    'order_public_id' => $order->public_id,
                    'error_code' => $payment->last_error_code,
                ],
            );
        });
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function handlePaymentIntentProcessing(array $intent, PaymentWebhookEvent $webhookEvent): void
    {
        DB::transaction(function () use ($intent, $webhookEvent) {
            $payment = $this->lockPaymentByIntent($intent['id'] ?? null);
            if (! $payment) {
                $this->markIgnored($webhookEvent);

                return;
            }

            $this->linkWebhook($webhookEvent, $payment);

            if (in_array($payment->status, [
                PaymentStatus::Paid->value,
                PaymentStatus::Cancelled->value,
                PaymentStatus::Refunded->value,
            ], true)) {
                return;
            }

            $payment->fill(['status' => PaymentStatus::Processing->value])->save();

            PaymentAttempt::query()
                ->where('payment_id', $payment->id)
                ->where('external_payment_intent_id', $payment->external_payment_intent_id)
                ->orderByDesc('id')
                ->limit(1)
                ->update(['status' => PaymentAttemptStatus::Processing->value]);
        });
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function handlePaymentIntentCanceled(array $intent, PaymentWebhookEvent $webhookEvent): void
    {
        DB::transaction(function () use ($intent, $webhookEvent) {
            $payment = $this->lockPaymentByIntent($intent['id'] ?? null);
            if (! $payment) {
                $this->markIgnored($webhookEvent);

                return;
            }

            $this->linkWebhook($webhookEvent, $payment);

            if ($payment->status === PaymentStatus::Paid->value) {
                return;
            }

            $order = $payment->order()->lockForUpdate()->firstOrFail();

            $payment->fill([
                'status' => PaymentStatus::Cancelled->value,
                'cancelled_at' => now(),
            ])->save();

            PaymentAttempt::query()
                ->where('payment_id', $payment->id)
                ->where('external_payment_intent_id', $payment->external_payment_intent_id)
                ->orderByDesc('id')
                ->limit(1)
                ->update([
                    'status' => PaymentAttemptStatus::Cancelled->value,
                    'completed_at' => now(),
                ]);

            if ($order->status === 'pending_payment') {
                $order->payment_status = PaymentStatus::Cancelled->value;
                $order->save();
                $this->transitions->transition($order, 'payment_failed', null, 'system', 'Payment cancelled');
            }

            $this->audit->log(
                action: 'payment.cancelled',
                auditable: $payment,
                restaurantId: $payment->restaurant_id,
                newValues: ['payment_public_id' => $payment->public_id],
            );
        });
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    private function handleChargeRefunded(array $charge, PaymentWebhookEvent $webhookEvent): void
    {
        DB::transaction(function () use ($charge, $webhookEvent) {
            $payment = $this->lockPaymentByChargeOrIntent(
                $charge['id'] ?? null,
                is_string($charge['payment_intent'] ?? null) ? $charge['payment_intent'] : ($charge['payment_intent']['id'] ?? null),
            );

            if (! $payment) {
                $this->markIgnored($webhookEvent);

                return;
            }

            $this->linkWebhook($webhookEvent, $payment);

            $amountRefunded = (int) ($charge['amount_refunded'] ?? 0);
            $this->applyRefundedAmount($payment, $amountRefunded);

            $refunds = $charge['refunds']['data'] ?? [];
            if (is_array($refunds)) {
                foreach ($refunds as $refundObj) {
                    if (is_array($refundObj)) {
                        $this->upsertRefundFromStripe($payment, $refundObj, RefundStatus::Succeeded->value);
                    }
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $refundObj
     */
    private function handleRefundUpdated(array $refundObj, PaymentWebhookEvent $webhookEvent): void
    {
        DB::transaction(function () use ($refundObj, $webhookEvent) {
            $payment = $this->findPaymentForRefundObject($refundObj);
            if (! $payment) {
                $this->markIgnored($webhookEvent);

                return;
            }

            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->linkWebhook($webhookEvent, $payment);

            $status = match ((string) ($refundObj['status'] ?? '')) {
                'succeeded' => RefundStatus::Succeeded->value,
                'pending', 'requires_action' => RefundStatus::Processing->value,
                'failed', 'canceled' => RefundStatus::Failed->value,
                default => RefundStatus::Processing->value,
            };

            $refund = $this->upsertRefundFromStripe($payment, $refundObj, $status);

            if ($status === RefundStatus::Succeeded->value) {
                $totalRefunded = (int) Refund::query()
                    ->where('payment_id', $payment->id)
                    ->where('status', RefundStatus::Succeeded->value)
                    ->sum('amount_cents');
                $this->applyRefundedAmount($payment, max($totalRefunded, (int) $payment->amount_refunded_cents));
            }

            unset($refund);
        });
    }

    /**
     * @param  array<string, mixed>  $refundObj
     */
    private function handleRefundFailed(array $refundObj, PaymentWebhookEvent $webhookEvent): void
    {
        DB::transaction(function () use ($refundObj, $webhookEvent) {
            $payment = $this->findPaymentForRefundObject($refundObj);
            if (! $payment) {
                $this->markIgnored($webhookEvent);

                return;
            }

            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->linkWebhook($webhookEvent, $payment);
            $this->upsertRefundFromStripe($payment, $refundObj, RefundStatus::Failed->value);
        });
    }

    /**
     * @param  array<string, mixed>  $dispute
     */
    private function handleDispute(array $dispute, PaymentWebhookEvent $webhookEvent): void
    {
        DB::transaction(function () use ($dispute, $webhookEvent) {
            $chargeId = is_string($dispute['charge'] ?? null)
                ? $dispute['charge']
                : ($dispute['charge']['id'] ?? null);
            $piId = is_string($dispute['payment_intent'] ?? null)
                ? $dispute['payment_intent']
                : ($dispute['payment_intent']['id'] ?? null);

            $payment = $this->lockPaymentByChargeOrIntent($chargeId, $piId);
            if (! $payment) {
                $this->markIgnored($webhookEvent);

                return;
            }

            $this->linkWebhook($webhookEvent, $payment);

            $externalId = (string) ($dispute['id'] ?? '');
            if ($externalId === '') {
                return;
            }

            $status = (string) ($dispute['status'] ?? 'needs_response');
            $openStatuses = ['needs_response', 'warning_needs_response', 'warning_under_review', 'under_review'];

            $row = PaymentDispute::query()
                ->where('external_dispute_id', $externalId)
                ->lockForUpdate()
                ->first();

            $payload = [
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
                'status' => $status,
                'reason' => $dispute['reason'] ?? null,
                'amount_cents' => (int) ($dispute['amount'] ?? 0),
                'currency' => strtoupper((string) ($dispute['currency'] ?? $payment->currency)),
                'evidence_due_at' => isset($dispute['evidence_details']['due_by'])
                    ? Carbon::createFromTimestamp($dispute['evidence_details']['due_by'])
                    : null,
                'provider_created_at' => isset($dispute['created'])
                    ? Carbon::createFromTimestamp($dispute['created'])
                    : null,
                'resolved_at' => in_array($status, ['won', 'lost', 'warning_closed', 'charge_refunded'], true)
                    ? now()
                    : null,
                'metadata' => [
                    'network_reason_code' => $dispute['network_reason_code'] ?? null,
                ],
            ];

            if ($row) {
                $row->fill($payload)->save();
            } else {
                PaymentDispute::query()->create(array_merge($payload, [
                    'public_id' => (string) Str::uuid(),
                    'external_dispute_id' => $externalId,
                ]));
            }

            if (in_array($status, $openStatuses, true)) {
                $payment->fill(['status' => PaymentStatus::Disputed->value])->save();
            }

            $this->audit->log(
                action: 'payment.disputed',
                auditable: $payment,
                restaurantId: $payment->restaurant_id,
                newValues: [
                    'external_dispute_id' => $externalId,
                    'status' => $status,
                ],
            );
        });
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private function handleAccountUpdated(array $account, PaymentWebhookEvent $webhookEvent): void
    {
        DB::transaction(function () use ($account, $webhookEvent) {
            $externalId = (string) ($account['id'] ?? '');
            if ($externalId === '') {
                $this->markIgnored($webhookEvent);

                return;
            }

            $row = RestaurantPaymentAccount::query()
                ->where('external_account_id', $externalId)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                $this->markIgnored($webhookEvent);

                return;
            }

            $currentlyDue = $account['requirements']['currently_due'] ?? [];
            $eventuallyDue = $account['requirements']['eventually_due'] ?? [];
            $disabledReason = $account['requirements']['disabled_reason'] ?? null;
            $detailsSubmitted = (bool) ($account['details_submitted'] ?? false);
            $chargesEnabled = (bool) ($account['charges_enabled'] ?? false);
            $payoutsEnabled = (bool) ($account['payouts_enabled'] ?? false);

            $onboardingStatus = 'not_started';
            if ($disabledReason) {
                $onboardingStatus = 'restricted';
            } elseif ($chargesEnabled && $detailsSubmitted && empty($currentlyDue)) {
                $onboardingStatus = 'active';
            } elseif ($detailsSubmitted || ! empty($currentlyDue) || ! empty($eventuallyDue)) {
                $onboardingStatus = 'pending';
            }

            $row->fill([
                'onboarding_status' => $onboardingStatus,
                'charges_enabled' => $chargesEnabled,
                'payouts_enabled' => $payoutsEnabled,
                'details_submitted' => $detailsSubmitted,
                'requirements_currently_due' => array_values(is_array($currentlyDue) ? $currentlyDue : []),
                'requirements_eventually_due' => array_values(is_array($eventuallyDue) ? $eventuallyDue : []),
                'disabled_reason' => $disabledReason,
                'last_synced_at' => now(),
                'onboarding_completed_at' => $onboardingStatus === 'active'
                    ? ($row->onboarding_completed_at ?? now())
                    : $row->onboarding_completed_at,
            ])->save();

            $webhookEvent->related_order_id = null;
            $webhookEvent->save();
        });
    }

    private function applyRefundedAmount(Payment $payment, int $amountRefundedCents): void
    {
        $received = (int) $payment->amount_received_cents > 0
            ? (int) $payment->amount_received_cents
            : (int) $payment->amount_cents;

        $amountRefundedCents = max((int) $payment->amount_refunded_cents, $amountRefundedCents);
        $status = $amountRefundedCents >= $received
            ? PaymentStatus::Refunded->value
            : PaymentStatus::PartiallyRefunded->value;

        $payment->fill([
            'amount_refunded_cents' => $amountRefundedCents,
            'status' => $status,
        ])->save();

        $order = $payment->order;
        if ($order) {
            $order->payment_status = $status;
            $order->save();
        }
    }

    /**
     * @param  array<string, mixed>  $refundObj
     */
    private function upsertRefundFromStripe(Payment $payment, array $refundObj, string $status): Refund
    {
        $externalId = (string) ($refundObj['id'] ?? '');
        $amount = (int) ($refundObj['amount'] ?? 0);

        $existing = null;
        if ($externalId !== '') {
            $existing = Refund::query()
                ->where('external_refund_id', $externalId)
                ->lockForUpdate()
                ->first();
        }

        if (! $existing) {
            $existing = Refund::query()
                ->where('payment_id', $payment->id)
                ->whereNull('external_refund_id')
                ->where('amount_cents', $amount)
                ->whereIn('status', [RefundStatus::Requested->value, RefundStatus::Processing->value])
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
        }

        $payload = [
            'external_refund_id' => $externalId !== '' ? $externalId : ($existing?->external_refund_id),
            'status' => $status,
            'amount_cents' => $amount > 0 ? $amount : ($existing?->amount_cents ?? 0),
            'currency' => strtoupper((string) ($refundObj['currency'] ?? $payment->currency)),
            'provider_failure_code' => $status === RefundStatus::Failed->value
                ? ($refundObj['failure_reason'] ?? 'refund_failed')
                : null,
            'provider_failure_message' => $status === RefundStatus::Failed->value
                ? PaymentErrorResponse::messageForCode('REFUND_NOT_ALLOWED')
                : null,
            'completed_at' => $status === RefundStatus::Succeeded->value ? now() : $existing?->completed_at,
            'failed_at' => $status === RefundStatus::Failed->value ? now() : null,
        ];

        if ($existing) {
            $existing->fill($payload)->save();

            return $existing;
        }

        return Refund::query()->create([
            'public_id' => (string) Str::uuid(),
            'payment_id' => $payment->id,
            'order_id' => $payment->order_id,
            'restaurant_id' => $payment->restaurant_id,
            'provider' => 'stripe',
            'reason_category' => 'provider',
            'idempotency_key' => 'stripe_refund:'.($externalId !== '' ? $externalId : Str::uuid()),
            'refund_application_fee' => true,
            'reverse_transfer' => (bool) $payment->connected_account_id,
            'requested_at' => now(),
            ...$payload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $refundObj
     */
    private function findPaymentForRefundObject(array $refundObj): ?Payment
    {
        $piId = is_string($refundObj['payment_intent'] ?? null)
            ? $refundObj['payment_intent']
            : ($refundObj['payment_intent']['id'] ?? null);
        $chargeId = is_string($refundObj['charge'] ?? null)
            ? $refundObj['charge']
            : ($refundObj['charge']['id'] ?? null);

        if ($externalRefundId = ($refundObj['id'] ?? null)) {
            $viaRefund = Refund::query()->where('external_refund_id', $externalRefundId)->first();
            if ($viaRefund) {
                return $viaRefund->payment;
            }
        }

        return $this->lockPaymentByChargeOrIntent($chargeId, $piId);
    }

    private function lockPaymentByIntent(?string $intentId): ?Payment
    {
        if (! $intentId) {
            return null;
        }

        return Payment::query()
            ->where('external_payment_intent_id', $intentId)
            ->lockForUpdate()
            ->first();
    }

    private function lockPaymentByChargeOrIntent(?string $chargeId, ?string $intentId): ?Payment
    {
        if ($chargeId) {
            $payment = Payment::query()->where('external_charge_id', $chargeId)->lockForUpdate()->first();
            if ($payment) {
                return $payment;
            }
        }

        return $this->lockPaymentByIntent($intentId);
    }

    private function linkWebhook(PaymentWebhookEvent $webhookEvent, Payment $payment): void
    {
        if (! $webhookEvent->exists) {
            return;
        }

        $webhookEvent->fill([
            'related_payment_id' => $payment->id,
            'related_order_id' => $payment->order_id,
        ])->save();
    }

    private function markIgnored(PaymentWebhookEvent $webhookEvent): void
    {
        if (! $webhookEvent->exists) {
            return;
        }

        $webhookEvent->fill([
            'processing_status' => WebhookProcessingStatus::Ignored->value,
            'processed_at' => now(),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function objectArray(Event $event): array
    {
        $object = $event->data->object ?? null;
        if (is_object($object) && method_exists($object, 'toArray')) {
            return $object->toArray();
        }
        if (is_array($object)) {
            return $object;
        }

        return [];
    }
}
