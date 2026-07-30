<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Domain\Payments\Services\RefundService;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Support\ApiResponse;
use App\Support\RestaurantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RestaurantPaymentController extends Controller
{
    public function __construct(
        private readonly RefundService $refunds,
    ) {}

    public function paymentSummary(Request $request, string $publicId)
    {
        $restaurantId = RestaurantContext::id($request);

        $order = Order::query()
            ->where('public_id', $publicId)
            ->where('restaurant_id', $restaurantId)
            ->firstOrFail();

        $payment = Payment::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->first();

        return ApiResponse::success([
            'payment' => $payment ? $this->summary($payment, $order) : null,
        ]);
    }

    public function requestRefund(Request $request, string $publicId)
    {
        $restaurantId = RestaurantContext::id($request);

        $order = Order::query()
            ->where('public_id', $publicId)
            ->where('restaurant_id', $restaurantId)
            ->firstOrFail();

        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'reason_category' => ['required', 'string', 'max:40'],
            'customer_reason' => ['nullable', 'string', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'confirm' => ['required', 'accepted'],
        ]);

        $payment = Payment::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $idempotencyKey = $data['idempotency_key']
            ?? $request->header('Idempotency-Key')
            ?? ('restaurant_refund:'.$order->public_id.':'.Str::uuid());

        $refund = $this->refunds->createLocalRequest(
            $payment,
            $request->user(),
            (int) $data['amount_cents'],
            $data['reason_category'],
            $data['customer_reason'] ?? null,
            $data['internal_note'] ?? null,
            $idempotencyKey,
        );

        return ApiResponse::success([
            'refund' => [
                'public_id' => $refund->public_id,
                'status' => $refund->status,
                'amount_cents' => $refund->amount_cents,
                'currency' => $refund->currency,
                'reason_category' => $refund->reason_category,
                'requested_at' => $refund->requested_at?->toIso8601String(),
            ],
        ], status: 201);
    }

    private function summary(Payment $payment, Order $order): array
    {
        return [
            'public_id' => $payment->public_id,
            'order_public_id' => $order->public_id,
            'order_number' => $order->order_number,
            'status' => $payment->status,
            'currency' => $payment->currency,
            'amount_cents' => $payment->amount_cents,
            'amount_received_cents' => $payment->amount_received_cents,
            'amount_refunded_cents' => $payment->amount_refunded_cents,
            'platform_fee_cents' => $payment->platform_fee_cents,
            'restaurant_share_cents' => $payment->restaurant_share_cents,
            'paid_at' => $payment->paid_at?->toIso8601String(),
        ];
    }
}
