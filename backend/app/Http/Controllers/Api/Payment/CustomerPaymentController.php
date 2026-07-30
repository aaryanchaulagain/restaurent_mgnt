<?php

namespace App\Http\Controllers\Api\Payment;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Services\PaymentRetryService;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class CustomerPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentRetryService $retries,
    ) {}

    public function show(Request $request, string $publicId)
    {
        $order = Order::query()
            ->where('public_id', $publicId)
            ->where('customer_id', $request->user()->id)
            ->firstOrFail();

        $payment = Payment::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->first();

        return ApiResponse::success([
            'payment' => $payment
                ? $this->summary($payment, $order, includeClientSecret: true)
                : null,
        ]);
    }

    public function retry(Request $request, string $publicId)
    {
        $order = Order::query()
            ->where('public_id', $publicId)
            ->where('customer_id', $request->user()->id)
            ->firstOrFail();

        $result = $this->retries->retry($order, $request->user());

        return ApiResponse::success([
            'payment' => $this->summary($result['payment'], $order, includeClientSecret: true),
            'client_secret' => $result['client_secret'],
            'publishable_key' => $result['publishable_key'],
        ]);
    }

    private function summary(Payment $payment, Order $order, bool $includeClientSecret): array
    {
        $data = [
            'public_id' => $payment->public_id,
            'order_public_id' => $order->public_id,
            'status' => $payment->status,
            'currency' => $payment->currency,
            'amount_cents' => $payment->amount_cents,
            'amount_received_cents' => $payment->amount_received_cents,
            'amount_refunded_cents' => $payment->amount_refunded_cents,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'failed_at' => $payment->failed_at?->toIso8601String(),
            'last_error_code' => $payment->last_error_code,
        ];

        $pendingStatuses = [
            PaymentStatus::Pending->value,
            PaymentStatus::RequiresAction->value,
            PaymentStatus::Processing->value,
        ];

        if ($includeClientSecret && in_array($payment->status, $pendingStatuses, true)) {
            $attempt = PaymentAttempt::query()
                ->where('payment_id', $payment->id)
                ->orderByDesc('id')
                ->first();

            if ($attempt?->client_secret_encrypted) {
                try {
                    $data['client_secret'] = Crypt::decryptString($attempt->client_secret_encrypted);
                    $data['publishable_key'] = config('payments.stripe.publishable_key');
                } catch (\Throwable) {
                    // omit client_secret if decrypt fails
                }
            }
        }

        return $data;
    }
}
