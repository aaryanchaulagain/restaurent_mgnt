<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Payments\Services\RefundService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Services\Auth\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminPaymentController extends Controller
{
    public function __construct(
        private readonly RefundService $refunds,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $query = Payment::query()
            ->with(['order:id,public_id,order_number', 'restaurant:id,public_id,trading_name,slug,ownership_type'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('provider')) {
            $query->where('provider', $request->string('provider'));
        }
        if ($request->filled('order_public_id')) {
            $query->whereHas('order', fn ($q) => $q->where('public_id', $request->string('order_public_id')));
        }
        if ($request->filled('restaurant_public_id')) {
            $rid = Restaurant::query()->where('public_id', $request->string('restaurant_public_id'))->value('id');
            if ($rid) {
                $query->where('restaurant_id', $rid);
            }
        }
        if ($request->filled('external_payment_intent_id')) {
            $query->where('external_payment_intent_id', $request->string('external_payment_intent_id'));
        }
        if ($request->filled('min_amount_cents')) {
            $query->where('amount_cents', '>=', (int) $request->input('min_amount_cents'));
        }
        if ($request->filled('max_amount_cents')) {
            $query->where('amount_cents', '<=', (int) $request->input('max_amount_cents'));
        }

        $payments = $query->paginate(min(50, (int) $request->input('per_page', 25)));

        return ApiResponse::success([
            'payments' => $payments->getCollection()->map(fn (Payment $p) => $this->listResource($p)),
        ], meta: [
            'current_page' => $payments->currentPage(),
            'last_page' => $payments->lastPage(),
            'total' => $payments->total(),
            'per_page' => $payments->perPage(),
        ]);
    }

    public function show(Request $request, string $publicId)
    {
        $payment = Payment::query()
            ->where('public_id', $publicId)
            ->with(['order', 'restaurant', 'refunds', 'disputes', 'attempts'])
            ->firstOrFail();

        $this->audit->log('admin.payment.viewed', $request->user(), $payment, restaurantId: $payment->restaurant_id);

        $audit = AuditLog::query()
            ->where(function ($q) use ($payment) {
                $q->where(function ($q2) use ($payment) {
                    $q2->where('auditable_type', Payment::class)
                        ->where('auditable_id', $payment->id);
                })->orWhere(function ($q2) use ($payment) {
                    $q2->where('auditable_type', \App\Models\Refund::class)
                        ->whereIn('auditable_id', $payment->refunds->pluck('id'));
                });
            })
            ->orderByDesc('created_at')
            ->limit(30)
            ->get()
            ->map(fn ($row) => [
                'action' => $row->action,
                'created_at' => $row->created_at?->toIso8601String(),
            ]);

        return ApiResponse::success([
            'payment' => $this->detailResource($payment),
            'audit' => $audit,
        ]);
    }

    public function createRefund(Request $request, string $publicId)
    {
        $payment = Payment::query()->where('public_id', $publicId)->firstOrFail();

        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'reason_category' => ['required', 'string', 'max:40'],
            'customer_reason' => ['nullable', 'string', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'confirm' => ['required', 'accepted'],
        ]);

        $amount = (int) $data['amount_cents'];
        $received = (int) $payment->amount_received_cents > 0
            ? (int) $payment->amount_received_cents
            : (int) $payment->amount_cents;
        $available = $received - (int) $payment->amount_refunded_cents;

        if ($amount >= $available && ! $request->user()->hasPermission('create_full_refund')) {
            return ApiResponse::error('Full refund permission required.', 403, code: 'REFUND_NOT_ALLOWED');
        }
        if ($amount < $available && ! $request->user()->hasPermission('create_partial_refund')
            && ! $request->user()->hasPermission('create_full_refund')) {
            return ApiResponse::error('Partial refund permission required.', 403, code: 'REFUND_NOT_ALLOWED');
        }

        $idempotencyKey = $data['idempotency_key']
            ?? $request->header('Idempotency-Key')
            ?? ('admin_refund:'.$payment->public_id.':'.Str::uuid());

        $refund = $this->refunds->requestRefund(
            $payment,
            $request->user(),
            $amount,
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
                'internal_note' => $refund->internal_note,
                'external_refund_id' => $refund->external_refund_id,
                'requested_at' => $refund->requested_at?->toIso8601String(),
            ],
        ], status: 201);
    }

    private function listResource(Payment $payment): array
    {
        return [
            'public_id' => $payment->public_id,
            'status' => $payment->status,
            'currency' => $payment->currency,
            'amount_cents' => $payment->amount_cents,
            'amount_received_cents' => $payment->amount_received_cents,
            'amount_refunded_cents' => $payment->amount_refunded_cents,
            'platform_fee_cents' => $payment->platform_fee_cents,
            'provider' => $payment->provider,
            'external_payment_intent_id' => $payment->external_payment_intent_id,
            'order_public_id' => $payment->order?->public_id,
            'order_number' => $payment->order?->order_number,
            'restaurant_public_id' => $payment->restaurant?->public_id,
            'restaurant_name' => $payment->restaurant?->trading_name,
            'ownership_type' => $payment->restaurant?->ownership_type,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }

    private function detailResource(Payment $payment): array
    {
        return array_merge($this->listResource($payment), [
            'external_charge_id' => $payment->external_charge_id,
            'connected_account_id' => $payment->connected_account_id,
            'restaurant_share_cents' => $payment->restaurant_share_cents,
            'processing_fee_cents' => $payment->processing_fee_cents,
            'last_error_code' => $payment->last_error_code,
            'last_error_message' => $payment->last_error_message,
            'metadata' => $payment->metadata,
            'attempts' => $payment->attempts->map(fn ($a) => [
                'public_id' => $a->public_id,
                'attempt_number' => $a->attempt_number,
                'status' => $a->status,
                'external_payment_intent_id' => $a->external_payment_intent_id,
                'started_at' => $a->started_at?->toIso8601String(),
                'completed_at' => $a->completed_at?->toIso8601String(),
            ]),
            'refunds' => $payment->refunds->map(fn ($r) => [
                'public_id' => $r->public_id,
                'status' => $r->status,
                'amount_cents' => $r->amount_cents,
                'reason_category' => $r->reason_category,
                'internal_note' => $r->internal_note,
                'external_refund_id' => $r->external_refund_id,
                'requested_at' => $r->requested_at?->toIso8601String(),
                'completed_at' => $r->completed_at?->toIso8601String(),
            ]),
            'disputes' => $payment->disputes->map(fn ($d) => [
                'public_id' => $d->public_id,
                'status' => $d->status,
                'reason' => $d->reason,
                'amount_cents' => $d->amount_cents,
                'external_dispute_id' => $d->external_dispute_id,
            ]),
        ]);
    }
}
