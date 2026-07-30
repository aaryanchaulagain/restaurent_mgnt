<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Restaurant;
use App\Services\Auth\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request)
    {
        $query = Order::query()
            ->with(['restaurant:id,public_id,trading_name,slug,ownership_type'])
            ->orderByDesc('placed_at');

        if ($request->filled('order_number')) {
            $query->where('order_number', 'like', '%'.$request->string('order_number').'%');
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->string('payment_status'));
        }
        if ($request->filled('fulfilment_type')) {
            $query->where('fulfilment_type', $request->string('fulfilment_type'));
        }
        if ($request->filled('restaurant_public_id')) {
            $rid = Restaurant::query()->where('public_id', $request->string('restaurant_public_id'))->value('id');
            if ($rid) {
                $query->where('restaurant_id', $rid);
            }
        }
        if ($request->filled('ownership_type')) {
            $query->whereHas('restaurant', fn ($q) => $q->where('ownership_type', $request->string('ownership_type')));
        }
        if ($request->filled('customer_email')) {
            $query->where('customer_email_snapshot', 'like', '%'.$request->string('customer_email').'%');
        }
        if ($request->boolean('guest_only')) {
            $query->whereNull('customer_id');
        }
        if ($request->boolean('authenticated_only')) {
            $query->whereNotNull('customer_id');
        }
        if ($request->filled('min_total_cents')) {
            $query->where('total_cents', '>=', (int) $request->input('min_total_cents'));
        }
        if ($request->filled('max_total_cents')) {
            $query->where('total_cents', '<=', (int) $request->input('max_total_cents'));
        }
        if ($request->filled('placed_from')) {
            $query->where('placed_at', '>=', $request->date('placed_from'));
        }
        if ($request->filled('placed_to')) {
            $query->where('placed_at', '<=', $request->date('placed_to'));
        }

        $orders = $query->paginate(min(50, (int) $request->input('per_page', 25)));

        return ApiResponse::success([
            'orders' => $orders->getCollection()->map(fn ($o) => $this->listResource($o)),
        ], meta: [
            'current_page' => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
            'total' => $orders->total(),
            'per_page' => $orders->perPage(),
        ]);
    }

    public function show(Request $request, string $publicId)
    {
        $order = Order::query()
            ->where('public_id', $publicId)
            ->with(['restaurant', 'items.modifiers', 'adjustments', 'statusHistory'])
            ->firstOrFail();

        $this->audit->log('admin.order.viewed', $request->user(), $order, metadata: [
            'order_number' => $order->order_number,
        ]);

        $audit = AuditLog::query()
            ->where('auditable_type', Order::class)
            ->where('auditable_id', $order->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'action' => $row->action,
                'created_at' => $row->created_at?->toIso8601String(),
            ]);

        return ApiResponse::success([
            'order' => $this->detailResource($order),
            'audit' => $audit,
        ]);
    }

    private function listResource(Order $order): array
    {
        return [
            'public_id' => $order->public_id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'fulfilment_type' => $order->fulfilment_type,
            'total_cents' => $order->total_cents,
            'currency' => $order->currency,
            'customer_name' => $order->customer_name_snapshot,
            'customer_email' => $order->customer_email_snapshot,
            'is_guest' => $order->customer_id === null,
            'placed_at' => $order->placed_at?->toIso8601String(),
            'restaurant' => $order->restaurant ? [
                'public_id' => $order->restaurant->public_id,
                'trading_name' => $order->restaurant->trading_name,
                'ownership_type' => $order->restaurant->ownership_type,
            ] : null,
        ];
    }

    private function detailResource(Order $order): array
    {
        $base = $this->listResource($order);

        return array_merge($base, [
            'subtotal_cents' => $order->subtotal_cents,
            'discount_cents' => $order->discount_cents,
            'tax_cents' => $order->tax_cents,
            'service_fee_cents' => $order->service_fee_cents,
            'delivery_fee_cents' => $order->delivery_fee_cents,
            'commission_rate_snapshot' => $order->commission_rate_snapshot,
            'commission_amount_cents' => $order->commission_amount_cents,
            'restaurant_net_estimate_cents' => $order->restaurant_net_estimate_cents,
            'delivery_address' => $order->delivery_address_snapshot,
            'pickup_instructions' => $order->pickup_instructions,
            'customer_notes' => $order->customer_notes,
            'idempotency' => [
                'recorded' => (bool) $order->idempotency_key,
                'replay_safe' => (bool) $order->idempotency_payload_hash,
            ],
            'items' => $order->items->map(fn ($i) => [
                'public_id' => $i->public_id,
                'name' => $i->item_name_snapshot,
                'variant' => $i->variant_name_snapshot,
                'quantity' => $i->quantity,
                'line_total_cents' => $i->line_total_cents,
                'modifiers' => $i->modifiers->map(fn ($m) => [
                    'group' => $m->group_name_snapshot,
                    'option' => $m->option_name_snapshot,
                    'price_adjustment_cents' => $m->price_adjustment_cents,
                ]),
            ]),
            'adjustments' => $order->adjustments->map(fn ($a) => [
                'type' => $a->type,
                'label' => $a->label,
                'amount_cents' => $a->amount_cents,
            ]),
            'timeline' => $order->statusHistory->map(fn ($h) => [
                'from' => $h->old_status,
                'to' => $h->new_status,
                'actor' => $h->actor_type,
                'at' => $h->created_at?->toIso8601String(),
                'reason' => $h->reason,
            ]),
        ]);
    }
}
