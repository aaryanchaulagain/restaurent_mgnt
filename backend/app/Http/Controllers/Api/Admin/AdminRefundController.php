<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Refund;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminRefundController extends Controller
{
    public function index(Request $request)
    {
        $query = Refund::query()
            ->with(['payment:id,public_id', 'order:id,public_id,order_number', 'restaurant:id,public_id,trading_name'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('restaurant_public_id')) {
            $query->whereHas('restaurant', fn ($q) => $q->where('public_id', $request->string('restaurant_public_id')));
        }

        $refunds = $query->paginate(min(50, (int) $request->input('per_page', 25)));

        return ApiResponse::success([
            'refunds' => $refunds->getCollection()->map(fn (Refund $r) => $this->resource($r)),
        ], meta: [
            'current_page' => $refunds->currentPage(),
            'last_page' => $refunds->lastPage(),
            'total' => $refunds->total(),
            'per_page' => $refunds->perPage(),
        ]);
    }

    public function show(string $publicId)
    {
        $refund = Refund::query()
            ->where('public_id', $publicId)
            ->with(['payment', 'order', 'restaurant', 'requestedBy:id,first_name,last_name,email', 'approvedBy:id,first_name,last_name,email'])
            ->firstOrFail();

        return ApiResponse::success([
            'refund' => $this->resource($refund, detailed: true),
        ]);
    }

    private function resource(Refund $refund, bool $detailed = false): array
    {
        $data = [
            'public_id' => $refund->public_id,
            'status' => $refund->status,
            'amount_cents' => $refund->amount_cents,
            'currency' => $refund->currency,
            'reason_category' => $refund->reason_category,
            'customer_reason' => $refund->customer_reason,
            'internal_note' => $refund->internal_note,
            'external_refund_id' => $refund->external_refund_id,
            'payment_public_id' => $refund->payment?->public_id,
            'order_public_id' => $refund->order?->public_id,
            'order_number' => $refund->order?->order_number,
            'restaurant_public_id' => $refund->restaurant?->public_id,
            'requested_at' => $refund->requested_at?->toIso8601String(),
            'completed_at' => $refund->completed_at?->toIso8601String(),
            'failed_at' => $refund->failed_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['provider_failure_code'] = $refund->provider_failure_code;
            $data['provider_failure_message'] = $refund->provider_failure_message;
            $data['refund_application_fee'] = $refund->refund_application_fee;
            $data['reverse_transfer'] = $refund->reverse_transfer;
            $data['idempotency_key'] = $refund->idempotency_key;
        }

        return $data;
    }
}
