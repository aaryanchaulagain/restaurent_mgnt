<?php

namespace App\Console\Commands;

use App\Domain\Payments\Contracts\PaymentProvider;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Services\StripeEventProcessor;
use App\Models\Payment;
use App\Services\Auth\AuditLogger;
use Illuminate\Console\Command;
use Throwable;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--payment= : Payment public ID to reconcile}';

    protected $description = 'Reconcile stale pending/processing payments against the provider.';

    public function handle(
        PaymentProvider $provider,
        StripeEventProcessor $processor,
        AuditLogger $audit,
    ): int {
        $query = Payment::query()
            ->whereIn('status', [
                PaymentStatus::Pending->value,
                PaymentStatus::Processing->value,
                PaymentStatus::RequiresAction->value,
            ])
            ->whereNotNull('external_payment_intent_id')
            ->orderBy('id');

        if ($publicId = $this->option('payment')) {
            $query->where('public_id', $publicId);
        } else {
            $query->where('updated_at', '<=', now()->subMinutes(5))->limit(50);
        }

        $count = 0;
        $mismatch = 0;

        foreach ($query->get() as $payment) {
            try {
                $result = $provider->retrievePaymentIntent($payment->external_payment_intent_id);

                if ($result->amountCents !== (int) $payment->amount_cents
                    || strtoupper($result->currency) !== strtoupper((string) $payment->currency)) {
                    $mismatch++;
                    $this->warn("Mismatch on payment {$payment->public_id}");
                    $audit->log(
                        action: 'payment.reconcile_mismatch',
                        auditable: $payment,
                        restaurantId: $payment->restaurant_id,
                        newValues: [
                            'provider_amount' => $result->amountCents,
                            'local_amount' => $payment->amount_cents,
                            'provider_currency' => $result->currency,
                            'local_currency' => $payment->currency,
                            'provider_status' => $result->rawStatus,
                        ],
                    );
                }

                $processor->applyPaymentIntentStatus($payment, [
                    'id' => $result->externalId,
                    'status' => $result->rawStatus,
                    'amount' => $result->amountCents,
                    'amount_received' => $result->amountCents,
                    'currency' => strtolower($result->currency),
                    'latest_charge' => $result->chargeId,
                ]);

                $audit->log(
                    action: 'payment.reconciled',
                    auditable: $payment,
                    restaurantId: $payment->restaurant_id,
                    newValues: [
                        'provider_status' => $result->rawStatus,
                        'payment_public_id' => $payment->public_id,
                    ],
                );

                $count++;
            } catch (Throwable $e) {
                $this->warn("Failed to reconcile {$payment->public_id}: {$e->getMessage()}");
            }
        }

        $this->info("Reconciled {$count} payments ({$mismatch} mismatches).");

        return self::SUCCESS;
    }
}
