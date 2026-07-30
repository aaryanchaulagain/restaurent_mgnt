<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Order\OrderTransitionService;
use App\Support\ApiResponse;
use App\Support\RestaurantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RestaurantOrderController extends Controller
{
    public function __construct(private readonly OrderTransitionService $transitions) {}

    public function index(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $query = Order::query()
            ->where('restaurant_id', $restaurantId)
            ->whereNotIn('status', ['pending_payment', 'payment_failed', 'draft'])
            ->with(['items.modifiers'])
            ->orderByDesc('placed_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        } else {
            $query->whereNotIn('status', ['pending_payment', 'payment_failed']);
        }

        $orders = $query->paginate(50);

        return ApiResponse::success([
            'orders' => $orders->getCollection()->map(fn ($o) => $this->restaurantOrderResource($o)),
        ], meta: [
            'current_page' => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
            'total' => $orders->total(),
        ]);
    }

    public function show(Request $request, string $publicId)
    {
        $restaurantId = RestaurantContext::id($request);
        $order = Order::query()
            ->where('restaurant_id', $restaurantId)
            ->where('public_id', $publicId)
            ->with(['items.modifiers', 'adjustments', 'statusHistory'])
            ->firstOrFail();

        return ApiResponse::success(['order' => $this->restaurantOrderResource($order)]);
    }

    public function accept(Request $request, string $publicId)
    {
        $order = $this->findOrder($request, $publicId);
        $data = $request->validate([
            'estimated_ready_minutes' => ['nullable', 'integer', 'min:1'],
        ]);
        $extra = [];
        if (! empty($data['estimated_ready_minutes'])) {
            $extra['estimated_ready_at'] = now()->addMinutes($data['estimated_ready_minutes']);
        }
        $order = $this->transitions->transition($order, 'accepted', $request->user(), 'restaurant_user', extra: $extra);
        return ApiResponse::success(['order' => $this->restaurantOrderResource($order)]);
    }

    public function reject(Request $request, string $publicId)
    {
        $order = $this->findOrder($request, $publicId);
        $data = $request->validate([
            'reason' => ['required', Rule::in(config('order.rejection_reasons'))],
            'explanation' => ['nullable', 'string', 'max:500'],
            'internal_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $order = $this->transitions->transition($order, 'rejected', $request->user(), 'restaurant_user', $data['reason'], $data);
        return ApiResponse::success(['order' => $this->restaurantOrderResource($order)]);
    }

    public function startPreparing(Request $request, string $publicId)
    {
        $order = $this->findOrder($request, $publicId);
        $order = $this->transitions->transition($order, 'preparing', $request->user(), 'restaurant_user');
        return ApiResponse::success(['order' => $this->restaurantOrderResource($order)]);
    }

    public function markReady(Request $request, string $publicId)
    {
        $order = $this->findOrder($request, $publicId);
        $order = $this->transitions->transition($order, 'ready_for_pickup', $request->user(), 'restaurant_user');
        return ApiResponse::success(['order' => $this->restaurantOrderResource($order)]);
    }

    public function completePickup(Request $request, string $publicId)
    {
        $order = $this->findOrder($request, $publicId);
        $order = $this->transitions->transition($order, 'completed_pickup', $request->user(), 'restaurant_user');
        return ApiResponse::success(['order' => $this->restaurantOrderResource($order)]);
    }

    public function cancel(Request $request, string $publicId)
    {
        $order = $this->findOrder($request, $publicId);
        $reason = $request->input('reason', 'Restaurant cancelled');
        $order = $this->transitions->transition($order, 'cancelled', $request->user(), 'restaurant_user', $reason);
        return ApiResponse::success(['order' => $this->restaurantOrderResource($order)]);
    }

    private function findOrder(Request $request, string $publicId): Order
    {
        $restaurantId = RestaurantContext::id($request);
        return Order::query()
            ->where('restaurant_id', $restaurantId)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function restaurantOrderResource(Order $order): array
    {
        return [
            'public_id' => $order->public_id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'fulfilment_type' => $order->fulfilment_type,
            'currency' => $order->currency,
            'customer_name' => $order->customer_name_snapshot,
            'customer_email' => $order->customer_email_snapshot,
            'customer_phone' => $order->customer_phone_snapshot,
            'subtotal_cents' => $order->subtotal_cents,
            'discount_cents' => $order->discount_cents,
            'tax_cents' => $order->tax_cents,
            'service_fee_cents' => $order->service_fee_cents,
            'delivery_fee_cents' => $order->delivery_fee_cents,
            'total_cents' => $order->total_cents,
            'commission_rate_snapshot' => $order->commission_rate_snapshot,
            'commission_amount_cents' => $order->commission_amount_cents,
            'restaurant_net_estimate_cents' => $order->restaurant_net_estimate_cents,
            'placed_at' => $order->placed_at?->toIso8601String(),
            'accepted_at' => $order->accepted_at?->toIso8601String(),
            'preparing_at' => $order->preparing_at?->toIso8601String(),
            'ready_at' => $order->ready_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'estimated_ready_at' => $order->estimated_ready_at?->toIso8601String(),
            'rejection_reason' => $order->rejection_reason,
            'rejection_explanation' => $order->rejection_explanation,
            'rejection_internal_note' => $order->rejection_internal_note,
            'cancellation_reason' => $order->cancellation_reason,
            'delivery_address' => $order->delivery_address_snapshot,
            'pickup_instructions' => $order->pickup_instructions,
            'delivery_instructions' => $order->delivery_instructions,
            'customer_notes' => $order->customer_notes,
            'items' => $order->relationLoaded('items') ? $order->items->map(fn ($i) => [
                'public_id' => $i->public_id,
                'name' => $i->item_name_snapshot,
                'description' => $i->item_description_snapshot,
                'variant' => $i->variant_name_snapshot,
                'sku' => $i->sku_snapshot,
                'unit_price_cents' => $i->unit_price_cents,
                'quantity' => $i->quantity,
                'line_total_cents' => $i->line_total_cents,
                'dietary' => $i->dietary_snapshot,
                'allergens' => $i->allergen_snapshot,
                'instructions' => $i->customer_instructions,
                'modifiers' => $i->relationLoaded('modifiers') ? $i->modifiers->map(fn ($m) => [
                    'group' => $m->group_name_snapshot,
                    'option' => $m->option_name_snapshot,
                    'price_adjustment_cents' => $m->price_adjustment_cents,
                ]) : [],
            ]) : [],
            'timeline' => $order->relationLoaded('statusHistory') ? $order->statusHistory->map(fn ($h) => [
                'from' => $h->old_status,
                'to' => $h->new_status,
                'actor' => $h->actor_type,
                'at' => $h->created_at?->toIso8601String(),
                'reason' => $h->reason,
            ]) : [],
        ];
    }
}
