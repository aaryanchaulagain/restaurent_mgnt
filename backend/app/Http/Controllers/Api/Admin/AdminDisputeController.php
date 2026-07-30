<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentDispute;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminDisputeController extends Controller
{
    public function index(Request $request)
    {
        $query = PaymentDispute::query()
            ->with(['payment:id,public_id', 'order:id,public_id,order_number'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $disputes = $query->paginate(min(50, (int) $request->input('per_page', 25)));

        return ApiResponse::success([
            'disputes' => $disputes->getCollection()->map(fn (PaymentDispute $d) => $this->resource($d)),
        ], meta: [
            'current_page' => $disputes->currentPage(),
            'last_page' => $disputes->lastPage(),
            'total' => $disputes->total(),
            'per_page' => $disputes->perPage(),
        ]);
    }

    public function show(string $publicId)
    {
        $dispute = PaymentDispute::query()
            ->where('public_id', $publicId)
            ->with(['payment', 'order'])
            ->firstOrFail();

        return ApiResponse::success([
            'dispute' => $this->resource($dispute, detailed: true),
        ]);
    }

    private function resource(PaymentDispute $dispute, bool $detailed = false): array
    {
        $data = [
            'public_id' => $dispute->public_id,
            'status' => $dispute->status,
            'reason' => $dispute->reason,
            'amount_cents' => $dispute->amount_cents,
            'currency' => $dispute->currency,
            'external_dispute_id' => $dispute->external_dispute_id,
            'payment_public_id' => $dispute->payment?->public_id,
            'order_public_id' => $dispute->order?->public_id,
            'evidence_due_at' => $dispute->evidence_due_at?->toIso8601String(),
            'resolved_at' => $dispute->resolved_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['metadata'] = $dispute->metadata;
            $data['provider_created_at'] = $dispute->provider_created_at?->toIso8601String();
        }

        return $data;
    }
}
