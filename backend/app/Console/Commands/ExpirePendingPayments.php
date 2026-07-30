<?php

namespace App\Console\Commands;

use App\Domain\Payments\Contracts\PaymentProvider;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Auth\AuditLogger;
use App\Services\Order\OrderTransitionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExpirePendingPayments extends Command
{
    protected $signature = 'payments:expire-pending';

    protected $description = 'Expire pending online payments past their deadline.';

    public function handle(
        PaymentProvider $provider,
        OrderTransitionService $transitions,
        AuditLogger $audit,
    ): int {
        $orders = Order::query()
            ->where('status', 'pending_payment')
            ->where('payment_method', 'online_card')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit(100)
            ->get();

        $count = 0;

        foreach ($orders as $order) {
            try {
                DB::transaction(function () use ($order, $provider, $transitions, $audit, &$count) {
                    /** @var Order $order */
                    $order = Order::query()->whereKey($order->id)->lockForUpdate()->first();
                    if (! $order || $order->status !== 'pending_payment') {
                        return;
                    }

                    if ($order->payment_status === PaymentStatus::Paid->value) {
                        return;
                    }

                    $paidExists = Payment::query()
                        ->where('order_id', $order->id)
                        ->where('status', PaymentStatus::Paid->value)
                        ->exists();

                    if ($paidExists) {
                        return;
                    }

                    $payment = Payment::query()
                        ->where('order_id', $order->id)
                        ->orderByDesc('id')
                        ->lockForUpdate()
                        ->first();

                    if ($payment?->external_payment_intent_id
                        && ! in_array($payment->status, [PaymentStatus::Paid->value, PaymentStatus::Cancelled->value], true)) {
                        try {
                            $provider->cancelPaymentIntent($payment->external_payment_intent_id);
                        } catch (Throwable) {
                            // Continue local expiry even if provider cancel fails.
                        }
                    }

                    if ($payment && $payment->status !== PaymentStatus::Paid->value) {
                        $payment->fill([
                            'status' => PaymentStatus::Cancelled->value,
                            'cancelled_at' => now(),
                            'last_error_code' => 'PAYMENT_CANCELLED',
                            'last_error_message' => 'Payment expired before completion.',
                        ])->save();
                    }

                    $order->payment_status = PaymentStatus::Cancelled->value;
                    $order->save();

                    $transitions->transition($order, 'payment_failed', null, 'system', 'Pending payment expired');

                    $audit->log(
                        action: 'payment.expired',
                        auditable: $payment ?? $order,
                        restaurantId: $order->restaurant_id,
                        newValues: ['order_public_id' => $order->public_id],
                    );

                    $count++;
                });
            } catch (Throwable $e) {
                $this->warn("Failed to expire order {$order->order_number}: {$e->getMessage()}");
            }
        }

        $this->info("Expired {$count} pending payments.");

        return self::SUCCESS;
    }
}
