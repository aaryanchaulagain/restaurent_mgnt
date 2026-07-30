<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Domain\Payments\Services\PaymentIntentService;
use App\Models\Order;
use App\Services\Order\OrderPlacementService;
use App\Services\Order\OrderStatusMachine;
use App\Services\Order\OrderTransitionService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Exceptions\OrderApiException;
use App\Support\OrderErrorResponse;

class CustomerOrderController extends Controller
{
    public function __construct(
        private readonly OrderPlacementService $placement,
        private readonly OrderTransitionService $transitions,
        private readonly PaymentIntentService $paymentIntents,
    ) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'checkout_quote_public_id' => ['required', 'uuid'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'payment_method' => ['nullable', Rule::in(['cash', 'online_card', 'pending_online_payment'])],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'pickup_instructions' => ['nullable', 'string', 'max:500'],
            'delivery_instructions' => ['nullable', 'string', 'max:500'],
            'customer_notes' => ['nullable', 'string', 'max:500'],
            'contactless_delivery' => ['nullable', 'boolean'],
            'payment_status' => ['prohibited'],
        ]);

        if (! $request->user()) {
            if (empty($data['customer_name']) || empty($data['customer_email'])) {
                throw ValidationException::withMessages([
                    'customer_name' => empty($data['customer_name']) ? ['Name is required for guest orders.'] : [],
                    'customer_email' => empty($data['customer_email']) ? ['Email is required for guest orders.'] : [],
                ]);
            }
        }

        $order = $this->placement->place($request, $data);

        $orderPayload = $this->orderResource($order);
        if ($order->guest_access_token) {
            $orderPayload['guest_access_token'] = $order->guest_access_token;
        }

        $response = [
            'order' => $orderPayload,
        ];

        $isOnline = ($order->is_online_payment ?? false)
            || $order->payment_method === 'online_card';

        if ($isOnline && $order->status === 'pending_payment') {
            $paymentResult = $this->paymentIntents->createForOrder($order);
            $response['payment'] = [
                'public_id' => $paymentResult['payment']->public_id,
                'status' => $paymentResult['payment']->status,
                'amount_cents' => $paymentResult['payment']->amount_cents,
                'currency' => $paymentResult['payment']->currency,
                'client_secret' => $paymentResult['client_secret'],
                'publishable_key' => $paymentResult['publishable_key'],
            ];
        }

        return ApiResponse::success($response, status: 201);
    }

    public function index(Request $request)
    {
        $orders = Order::query()
            ->where('customer_id', $request->user()->id)
            ->with(['items'])
            ->orderByDesc('placed_at')
            ->paginate(20);

        return ApiResponse::success([
            'orders' => $orders->getCollection()->map(fn ($o) => $this->orderResource($o)),
        ], meta: [
            'current_page' => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
            'total' => $orders->total(),
        ]);
    }

    public function show(Request $request, string $publicId)
    {
        $order = Order::query()
            ->where('customer_id', $request->user()->id)
            ->where('public_id', $publicId)
            ->with(['items.modifiers', 'adjustments', 'statusHistory'])
            ->firstOrFail();

        return ApiResponse::success(['order' => $this->orderResource($order)]);
    }

    public function cancel(Request $request, string $publicId)
    {
        $order = Order::query()
            ->where('customer_id', $request->user()->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        if (! in_array($order->status, OrderStatusMachine::customerCancellableStatuses(), true)) {
            throw new OrderApiException(
                'ORDER_CANCELLATION_NOT_ALLOWED',
                OrderErrorResponse::messageForCode('ORDER_CANCELLATION_NOT_ALLOWED'),
                422,
            );
        }

        $reason = $request->input('reason', 'Customer cancelled');
        $order = $this->transitions->transition($order, 'cancelled', $request->user(), 'customer', $reason);

        return ApiResponse::success(['order' => $this->orderResource($order)]);
    }

    public function guestShow(Request $request, string $orderNumber)
    {
        $token = $request->input('token') ?? $request->header('X-Guest-Order-Token');
        if (! $token) {
            throw ValidationException::withMessages(['token' => ['Guest verification token is required.']]);
        }

        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->where('guest_token_hash', hash('sha256', $token))
            ->with(['items.modifiers', 'adjustments', 'statusHistory'])
            ->firstOrFail();

        return ApiResponse::success(['order' => $this->orderResource($order)]);
    }

    private function orderResource(Order $order): array
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
            'subtotal_cents' => $order->subtotal_cents,
            'discount_cents' => $order->discount_cents,
            'tax_cents' => $order->tax_cents,
            'service_fee_cents' => $order->service_fee_cents,
            'delivery_fee_cents' => $order->delivery_fee_cents,
            'total_cents' => $order->total_cents,
            'placed_at' => $order->placed_at?->toIso8601String(),
            'accepted_at' => $order->accepted_at?->toIso8601String(),
            'preparing_at' => $order->preparing_at?->toIso8601String(),
            'ready_at' => $order->ready_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'estimated_ready_at' => $order->estimated_ready_at?->toIso8601String(),
            'rejection_reason' => $order->rejection_reason,
            'rejection_explanation' => $order->rejection_explanation,
            'cancellation_reason' => $order->cancellation_reason,
            'delivery_address' => $order->delivery_address_snapshot,
            'pickup_instructions' => $order->pickup_instructions,
            'customer_notes' => $order->customer_notes,
            'items' => $order->relationLoaded('items') ? $order->items->map(fn ($i) => [
                'public_id' => $i->public_id,
                'name' => $i->item_name_snapshot,
                'description' => $i->item_description_snapshot,
                'variant' => $i->variant_name_snapshot,
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
            'adjustments' => $order->relationLoaded('adjustments') ? $order->adjustments->map(fn ($a) => [
                'type' => $a->type,
                'label' => $a->label,
                'amount_cents' => $a->amount_cents,
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
