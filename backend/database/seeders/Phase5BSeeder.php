<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\OrderStatusHistory;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class Phase5BSeeder extends Seeder
{
    public function run(): void
    {
        $customer = User::query()->where('email', 'customer@example.com')->first();
        $suvakamana = Restaurant::query()->where('slug', config('suvakamana.platform_restaurant_slug'))->first();
        $partner = Restaurant::query()->where('slug', 'golden-wok')->first();

        if (! $suvakamana || ! $partner || ! $customer) {
            return;
        }

        $suvItem = $suvakamana->menus()->first()?->categories()->first()?->items()->first();
        $partnerItem = $partner->menus()->first()?->categories()->first()?->items()->first();
        $momoItem = MenuItem::query()
            ->where('restaurant_id', $suvakamana->id)
            ->where('slug', 'chicken-momo')
            ->first() ?? $suvItem;

        if (! $suvItem || ! $partnerItem || ! $momoItem) {
            return;
        }

        $scenarios = [
            ['SVK-SEED-000001', $suvakamana, $suvItem, 'awaiting_restaurant', $customer, false, 0.0, []],
            ['SVK-SEED-000002', $suvakamana, $suvItem, 'accepted', $customer, false, 0.0, []],
            ['SVK-SEED-000003', $suvakamana, $suvItem, 'preparing', $customer, false, 0.0, []],
            ['SVK-SEED-000004', $suvakamana, $suvItem, 'ready_for_pickup', $customer, false, 0.0, []],
            ['SVK-SEED-000005', $suvakamana, $suvItem, 'completed_pickup', $customer, false, 0.0, []],
            ['SVK-SEED-000006', $partner, $partnerItem, 'awaiting_restaurant', $customer, false, 0.125, []],
            ['SVK-SEED-000007', $partner, $partnerItem, 'rejected', $customer, false, 0.125, []],
            ['SVK-SEED-000008', $suvakamana, $suvItem, 'cancelled', $customer, false, 0.0, ['cancellation_actor_type' => 'customer']],
            ['SVK-SEED-000009', $suvakamana, $suvItem, 'cancelled', $customer, false, 0.0, ['cancellation_actor_type' => 'restaurant_user']],
            ['SVK-SEED-000010', $suvakamana, $suvItem, 'expired', $customer, false, 0.0, []],
            ['SVK-SEED-000011', $suvakamana, $suvItem, 'awaiting_restaurant', null, true, 0.0, []],
            ['SVK-SEED-000012', $suvakamana, $momoItem, 'awaiting_restaurant', $customer, false, 0.0, ['modifier_heavy' => true]],
            ['SVK-SEED-000013', $suvakamana, $suvItem, 'awaiting_restaurant', $customer, false, 0.0, ['discount_cents' => 500]],
            ['SVK-SEED-000014', $suvakamana, $suvItem, 'awaiting_restaurant', $customer, false, 0.0, ['commission_demo' => 'first_party_zero']],
            ['SVK-SEED-000015', $partner, $partnerItem, 'awaiting_restaurant', $customer, false, 0.125, ['commission_demo' => 'third_party_snapshot']],
            [
                'SVK-SEED-000016',
                $suvakamana,
                $suvItem,
                'awaiting_restaurant',
                $customer,
                false,
                0.0,
                ['customer_notes' => 'Phase 5B price-change demo: compare cart quote before placing.'],
            ],
            [
                'SVK-SEED-000017',
                $suvakamana,
                $suvItem,
                'completed_pickup',
                $customer,
                false,
                0.0,
                [
                    'idempotency_scope' => 'customer:'.$customer->id,
                    'idempotency_key' => 'seed-idempotency-replay-017',
                    'idempotency_payload_hash' => hash('sha256', 'phase5b-seed-idempotency-replay-017'),
                ],
            ],
        ];

        foreach ($scenarios as $scenario) {
            $this->upsertScenario(...$scenario);
        }
    }

    /**
     * @param  array<string, mixed>  $extras
     */
    private function upsertScenario(
        string $orderNumber,
        Restaurant $restaurant,
        $menuItem,
        string $status,
        ?User $customer,
        bool $guest,
        float $commissionRate,
        array $extras = [],
    ): void {
        $modifierHeavy = (bool) ($extras['modifier_heavy'] ?? false);
        $discountCents = (int) ($extras['discount_cents'] ?? 0);
        $unitPrice = (int) $menuItem->base_price_cents;
        $modifierTotal = $modifierHeavy ? 300 : 0;
        $lineSubtotal = $unitPrice + $modifierTotal;
        $subtotal = $lineSubtotal;
        $total = max(0, $subtotal - $discountCents);
        $commissionAmount = $commissionRate > 0 ? (int) round($subtotal * $commissionRate) : 0;

        $order = Order::query()->firstOrNew(['order_number' => $orderNumber]);
        if (! $order->exists) {
            $order->public_id = (string) Str::uuid();
        }

        $placedAt = now()->subMinutes(30);
        $cancellationActor = $extras['cancellation_actor_type'] ?? null;

        $order->fill([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $guest ? null : $customer?->id,
            'guest_token_hash' => $guest ? hash('sha256', 'phase5b-guest-demo-token') : null,
            'status' => $status,
            'payment_method' => 'cash',
            'payment_status' => 'unpaid',
            'fulfilment_type' => 'pickup',
            'currency' => 'AUD',
            'customer_name_snapshot' => $guest ? 'Guest Demo' : ($customer?->name ?? 'Demo Customer'),
            'customer_email_snapshot' => $guest ? 'guest.demo@example.com' : ($customer?->email ?? 'demo@example.com'),
            'customer_phone_snapshot' => '+61400000000',
            'customer_notes' => $extras['customer_notes'] ?? null,
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discountCents,
            'total_cents' => $total,
            'commission_rate_snapshot' => $commissionRate,
            'commission_amount_cents' => $commissionAmount,
            'restaurant_net_estimate_cents' => max(0, $total - $commissionAmount),
            'placed_at' => $placedAt,
            'expires_at' => $status === 'awaiting_restaurant' ? now()->addMinutes(10) : now()->subHour(),
            'cancellation_actor_type' => $status === 'cancelled' ? $cancellationActor : null,
            'cancellation_reason' => $status === 'cancelled'
                ? ($cancellationActor === 'restaurant_user' ? 'Restaurant cancelled order' : 'Customer changed plans')
                : ($status === 'expired' ? 'Acceptance timeout expired' : null),
            'accepted_at' => in_array($status, ['accepted', 'preparing', 'ready_for_pickup', 'completed_pickup'], true) ? $placedAt->copy()->addMinutes(5) : null,
            'preparing_at' => in_array($status, ['preparing', 'ready_for_pickup', 'completed_pickup'], true) ? $placedAt->copy()->addMinutes(10) : null,
            'ready_at' => in_array($status, ['ready_for_pickup', 'completed_pickup'], true) ? $placedAt->copy()->addMinutes(20) : null,
            'completed_at' => $status === 'completed_pickup' ? $placedAt->copy()->addMinutes(25) : null,
            'rejected_at' => $status === 'rejected' ? $placedAt->copy()->addMinutes(8) : null,
            'cancelled_at' => in_array($status, ['cancelled', 'expired'], true) ? $placedAt->copy()->addMinutes(12) : null,
            'rejection_reason' => $status === 'rejected' ? 'item_unavailable' : null,
            'rejection_explanation' => $status === 'rejected' ? 'Kitchen cannot fulfil this order today.' : null,
            'idempotency_key' => $extras['idempotency_key'] ?? 'seed-'.$orderNumber,
            'idempotency_scope' => $extras['idempotency_scope'] ?? null,
            'idempotency_payload_hash' => $extras['idempotency_payload_hash'] ?? null,
        ]);
        $order->save();

        $order->items()->delete();
        $orderItem = OrderItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'menu_item_id' => $menuItem->id,
            'item_name_snapshot' => $menuItem->name,
            'item_description_snapshot' => $menuItem->short_description,
            'unit_price_cents' => $unitPrice,
            'quantity' => 1,
            'line_subtotal_cents' => $lineSubtotal,
            'line_total_cents' => $lineSubtotal,
        ]);

        if ($modifierHeavy) {
            OrderItemModifier::query()->create([
                'order_item_id' => $orderItem->id,
                'group_name_snapshot' => 'Spice Level',
                'option_name_snapshot' => 'Extra Hot',
                'price_adjustment_cents' => 100,
                'quantity' => 1,
                'total_adjustment_cents' => 100,
            ]);
            OrderItemModifier::query()->create([
                'order_item_id' => $orderItem->id,
                'group_name_snapshot' => 'Cooking Style',
                'option_name_snapshot' => 'Fried',
                'price_adjustment_cents' => 200,
                'quantity' => 1,
                'total_adjustment_cents' => 200,
            ]);
        }

        $order->adjustments()->delete();
        if ($discountCents > 0) {
            OrderAdjustment::query()->create([
                'order_id' => $order->id,
                'type' => 'discount',
                'label' => 'Restaurant-funded offer',
                'amount_cents' => -$discountCents,
            ]);
        }

        $order->statusHistory()->delete();
        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'old_status' => null,
            'new_status' => 'awaiting_restaurant',
            'actor_type' => $guest ? 'system' : 'customer',
            'actor_user_id' => $guest ? null : $customer?->id,
            'created_at' => $placedAt,
        ]);

        if ($status !== 'awaiting_restaurant') {
            $transitionActor = match (true) {
                in_array($status, ['cancelled', 'expired'], true) => $status === 'expired' ? 'system' : ($cancellationActor ?? 'customer'),
                in_array($status, ['rejected'], true) => 'restaurant_user',
                default => 'restaurant_user',
            };

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'old_status' => 'awaiting_restaurant',
                'new_status' => $status,
                'actor_type' => $transitionActor,
                'actor_user_id' => $transitionActor === 'customer' ? $customer?->id : null,
                'created_at' => $placedAt->copy()->addMinutes(10),
            ]);
        }
    }
}
