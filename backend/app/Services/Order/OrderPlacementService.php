<?php

namespace App\Services\Order;

use App\Exceptions\OrderApiException;
use App\Models\Cart;
use App\Models\CheckoutQuote;
use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Services\Cart\CartPricingService;
use App\Support\OrderErrorResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderPlacementService
{
    public function __construct(
        private readonly OrderNumberGenerator $numbers,
        private readonly OrderSnapshotService $snapshots,
        private readonly CartPricingService $pricing,
        private readonly AuditLogger $audit,
        private readonly OrderIdempotencyHasher $idempotencyHasher,
        private readonly OrderEventDispatcher $events,
    ) {}

    public function place(Request $request, array $input): Order
    {
        $idempotencyKey = $input['idempotency_key'] ?? $request->header('Idempotency-Key');
        if (! $idempotencyKey) {
            throw new OrderApiException('ORDER_ACCESS_DENIED', 'Idempotency key is required.', 422);
        }

        $paymentMethod = $input['payment_method'] ?? 'cash';
        if ($paymentMethod === 'pending_online_payment') {
            $paymentMethod = 'online_card';
        }
        if (! in_array($paymentMethod, ['cash', 'online_card'], true)) {
            throw new OrderApiException('ORDER_ACCESS_DENIED', 'Invalid payment method.', 422);
        }
        if (($input['payment_status'] ?? null) === 'paid') {
            throw new OrderApiException('ORDER_ACCESS_DENIED', 'Payment status cannot be set manually.', 422);
        }

        $quoteId = $input['checkout_quote_public_id'] ?? null;
        if (! $quoteId) {
            throw new OrderApiException('CHECKOUT_QUOTE_EXPIRED', 'Checkout quote is required.', 422);
        }

        try {
            return DB::transaction(function () use ($request, $input, $idempotencyKey, $quoteId, $paymentMethod) {
                $quote = CheckoutQuote::query()
                    ->where('public_id', $quoteId)
                    ->lockForUpdate()
                    ->first();

                if (! $quote) {
                    throw new OrderApiException('CHECKOUT_QUOTE_EXPIRED', OrderErrorResponse::messageForCode('CHECKOUT_QUOTE_EXPIRED'), 422);
                }

                $cart = Cart::query()
                    ->where('id', $quote->cart_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $scope = $this->idempotencyHasher->customerScope($request->user()?->id, $cart);
                $payloadHash = $this->idempotencyHasher->hash($quote, $cart, $scope, array_merge($input, [
                    'payment_method' => $paymentMethod,
                ]));

                $existing = Order::query()
                    ->where('idempotency_scope', $scope)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if ($existing->idempotency_payload_hash === $payloadHash) {
                        return $existing->load(['items.modifiers', 'adjustments', 'statusHistory']);
                    }

                    throw new OrderApiException(
                        'IDEMPOTENCY_KEY_REUSED',
                        OrderErrorResponse::messageForCode('IDEMPOTENCY_KEY_REUSED'),
                        409,
                    );
                }

                $this->validateQuote($quote, $request->user());
                $this->validateCart($cart, $request->user());

                $pricing = $this->pricing->calculate($cart, true);
                foreach ($pricing['warnings'] as $w) {
                    $code = $w['code'];
                    if ($code === 'ITEM_UNAVAILABLE') {
                        throw new OrderApiException('ITEM_UNAVAILABLE', OrderErrorResponse::messageForCode('ITEM_UNAVAILABLE'), 422);
                    }
                    if ($code === 'ITEM_PRICE_CHANGED' || $code === 'MODIFIER_PRICE_CHANGED') {
                        throw new OrderApiException('PRICE_CHANGED', OrderErrorResponse::messageForCode('PRICE_CHANGED'), 422);
                    }
                    if ($code === 'MINIMUM_ORDER_NOT_MET') {
                        throw new OrderApiException('MINIMUM_ORDER_NOT_MET', OrderErrorResponse::messageForCode('MINIMUM_ORDER_NOT_MET'), 422);
                    }
                }

                if (! $pricing['minimum_order_met']) {
                    throw new OrderApiException('MINIMUM_ORDER_NOT_MET', OrderErrorResponse::messageForCode('MINIMUM_ORDER_NOT_MET'), 422);
                }

                $restaurant = $cart->restaurant;
                if (! $restaurant || $restaurant->suspended_at || ! $restaurant->accepting_orders) {
                    throw new OrderApiException('RESTAURANT_UNAVAILABLE', OrderErrorResponse::messageForCode('RESTAURANT_UNAVAILABLE'), 422);
                }

                $commission = $this->snapshots->snapshotCommission($restaurant);
                $commissionAmount = $commission['rate'] > 0
                    ? (int) round($pricing['subtotal_cents'] * $commission['rate'])
                    : 0;
                $netEstimate = $pricing['total_before_delivery_cents'] - $commissionAmount;

                $guestTokenHash = null;
                $guestToken = null;
                if (! $request->user()) {
                    $guestToken = Str::random(64);
                    $guestTokenHash = hash('sha256', $guestToken);
                }

                $isOnline = $paymentMethod === 'online_card';
                $paymentStatus = $isOnline ? 'pending' : 'unpaid';
                $orderStatus = $isOnline ? 'pending_payment' : 'awaiting_restaurant';
                $expiresAt = $isOnline
                    ? now()->addMinutes(config('payments.pending_expiry_minutes', 30))
                    : now()->addMinutes(config('order.acceptance_timeout_minutes', 10));

                $order = Order::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'order_number' => $this->numbers->generate(),
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_scope' => $scope,
                    'idempotency_payload_hash' => $payloadHash,
                    'restaurant_id' => $restaurant->id,
                    'customer_id' => $request->user()?->id,
                    'cart_id' => $cart->id,
                    'checkout_quote_id' => $quote->id,
                    'guest_token_hash' => $guestTokenHash,
                    'status' => $orderStatus,
                    'payment_method' => $paymentMethod,
                    'payment_status' => $paymentStatus,
                    'payment_provider' => $isOnline ? 'stripe' : null,
                    'fulfilment_type' => $quote->fulfilment_type,
                    'currency' => $cart->currency ?: 'AUD',
                    'customer_name_snapshot' => $input['customer_name'] ?? $request->user()?->name,
                    'customer_email_snapshot' => $input['customer_email'] ?? $request->user()?->email,
                    'customer_phone_snapshot' => $input['customer_phone'] ?? null,
                    'delivery_address_snapshot' => $quote->address_snapshot,
                    'pickup_instructions' => $input['pickup_instructions'] ?? null,
                    'delivery_instructions' => $input['delivery_instructions'] ?? null,
                    'customer_notes' => $input['customer_notes'] ?? null,
                    'contactless_delivery' => $input['contactless_delivery'] ?? false,
                    'subtotal_cents' => $pricing['subtotal_cents'],
                    'discount_cents' => $pricing['discount_cents'],
                    'tax_cents' => $pricing['tax_cents'],
                    'service_fee_cents' => $pricing['service_fee_cents'],
                    'delivery_fee_cents' => $pricing['delivery_fee_cents'] ?? 0,
                    'total_cents' => $pricing['total_before_delivery_cents'],
                    'commission_rate_snapshot' => $commission['rate'],
                    'commission_amount_cents' => $commissionAmount,
                    'restaurant_net_estimate_cents' => max(0, $netEstimate),
                    'placed_at' => now(),
                    'expires_at' => $expiresAt,
                ]);

                $this->snapshots->snapshotItems($order->id, $cart);

                if ($pricing['discount_cents'] > 0) {
                    OrderAdjustment::query()->create([
                        'order_id' => $order->id,
                        'type' => 'discount',
                        'label' => 'Restaurant discount',
                        'amount_cents' => -$pricing['discount_cents'],
                    ]);
                }
                if ($pricing['tax_cents'] > 0) {
                    OrderAdjustment::query()->create([
                        'order_id' => $order->id,
                        'type' => 'tax',
                        'label' => 'Tax',
                        'amount_cents' => $pricing['tax_cents'],
                    ]);
                }
                if ($pricing['service_fee_cents'] > 0) {
                    OrderAdjustment::query()->create([
                        'order_id' => $order->id,
                        'type' => 'service_fee',
                        'label' => 'Service fee',
                        'amount_cents' => $pricing['service_fee_cents'],
                    ]);
                }

                OrderStatusHistory::query()->create([
                    'order_id' => $order->id,
                    'old_status' => null,
                    'new_status' => $orderStatus,
                    'actor_user_id' => $request->user()?->id,
                    'actor_type' => $request->user() ? 'customer' : 'system',
                ]);

                $quote->update(['status' => 'converted', 'converted_order_id' => $order->id]);
                $cart->update(['status' => 'converted']);

                $this->audit->log('order.created', $request->user(), $order, restaurantId: $restaurant->id, request: $request);

                $order->guest_access_token = $guestToken;
                $order->is_online_payment = $isOnline;

                if (! $isOnline) {
                    DB::afterCommit(function () use ($order) {
                        $this->events->placed($order->fresh(['items']));
                    });
                }

                return $order->load(['items.modifiers', 'adjustments', 'statusHistory']);
            });
        } catch (QueryException $e) {
            if (! $this->isDuplicateIdempotency($e)) {
                throw $e;
            }

            $quote = CheckoutQuote::query()->where('public_id', $quoteId)->first();
            $cart = $quote?->cart;
            if (! $quote || ! $cart) {
                throw $e;
            }

            $scope = $this->idempotencyHasher->customerScope($request->user()?->id, $cart);
            $existing = Order::query()
                ->where('idempotency_scope', $scope)
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();

            $payloadHash = $this->idempotencyHasher->hash($quote, $cart, $scope, array_merge($input, [
                'payment_method' => $paymentMethod,
            ]));

            if ($existing->idempotency_payload_hash === $payloadHash) {
                return $existing->load(['items.modifiers', 'adjustments', 'statusHistory']);
            }

            throw new OrderApiException(
                'IDEMPOTENCY_KEY_REUSED',
                OrderErrorResponse::messageForCode('IDEMPOTENCY_KEY_REUSED'),
                409,
            );
        }
    }

    private function validateQuote(CheckoutQuote $quote, ?User $user): void
    {
        if (($quote->status ?? 'active') === 'converted') {
            throw new OrderApiException('CHECKOUT_QUOTE_CONVERTED', OrderErrorResponse::messageForCode('CHECKOUT_QUOTE_CONVERTED'), 422);
        }
        if ($quote->expires_at && $quote->expires_at->isPast()) {
            throw new OrderApiException('CHECKOUT_QUOTE_EXPIRED', OrderErrorResponse::messageForCode('CHECKOUT_QUOTE_EXPIRED'), 422);
        }
        if ($user && $quote->customer_id && $quote->customer_id !== $user->id) {
            throw new OrderApiException('ORDER_ACCESS_DENIED', OrderErrorResponse::messageForCode('ORDER_ACCESS_DENIED'), 403);
        }
    }

    private function validateCart(Cart $cart, ?User $user): void
    {
        if ($cart->status === 'converted') {
            throw new OrderApiException('CART_CHANGED', OrderErrorResponse::messageForCode('CART_CHANGED'), 422);
        }
        if ($cart->items()->count() === 0) {
            throw new OrderApiException('CART_CHANGED', OrderErrorResponse::messageForCode('CART_CHANGED'), 422);
        }
    }

    private function isDuplicateIdempotency(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'orders_idempotency_scope_key_unique');
    }
}
