<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Support\PaymentErrorResponse;
use Illuminate\Support\Facades\DB;

class PaymentRetryService
{
    public function __construct(
        private readonly PaymentIntentService $intentService,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{payment: Payment, client_secret: string|null, publishable_key: string|null}
     */
    public function retry(Order $order, User $actor): array
    {
        return DB::transaction(function () use ($order, $actor) {
            /** @var Order $order */
            $order = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($order->status, ['pending_payment', 'payment_failed'], true)) {
                throw new PaymentException(
                    'PAYMENT_RETRY_NOT_ALLOWED',
                    PaymentErrorResponse::messageForCode('PAYMENT_RETRY_NOT_ALLOWED'),
                    422,
                );
            }

            if ($order->payment_status === PaymentStatus::Paid->value) {
                throw new PaymentException(
                    'PAYMENT_ALREADY_PAID',
                    PaymentErrorResponse::messageForCode('PAYMENT_ALREADY_PAID'),
                    409,
                );
            }

            $payment = Payment::query()
                ->where('order_id', $order->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($payment && $payment->status === PaymentStatus::Paid->value) {
                throw new PaymentException(
                    'PAYMENT_ALREADY_PAID',
                    PaymentErrorResponse::messageForCode('PAYMENT_ALREADY_PAID'),
                    409,
                );
            }

            if ($payment && in_array($payment->status, [
                PaymentStatus::Processing->value,
            ], true)) {
                throw new PaymentException(
                    'PAYMENT_PROCESSING',
                    PaymentErrorResponse::messageForCode('PAYMENT_PROCESSING'),
                    409,
                );
            }

            if ($order->status === 'payment_failed') {
                $order->status = 'pending_payment';
                $order->payment_status = PaymentStatus::Pending->value;
                $order->save();
            }

            $this->audit->log(
                action: 'payment.retried',
                actor: $actor,
                auditable: $payment ?? $order,
                restaurantId: $order->restaurant_id,
                newValues: [
                    'order_public_id' => $order->public_id,
                    'order_status' => $order->status,
                    'payment_public_id' => $payment?->public_id,
                ],
            );

            return $this->intentService->createForOrder($order->fresh());
        });
    }
}
