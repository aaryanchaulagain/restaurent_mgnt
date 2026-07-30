<?php

namespace App\Services\Order;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CheckoutQuote;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\Restaurant;
use App\Models\RestaurantCommissionAgreement;
use Illuminate\Support\Collection;

class OrderSnapshotService
{
    public function snapshotItems(int $orderId, Cart $cart): Collection
    {
        $cart->load(['items.menuItem.allergens', 'items.variant', 'items.modifiers.modifierOption.modifierGroup']);

        return $cart->items->map(function (CartItem $line) use ($orderId) {
            $item = $line->menuItem;
            $variant = $line->variant;

            $unitPrice = $variant && $variant->is_active
                ? (int) $variant->price_cents
                : (int) ($item?->base_price_cents ?? 0);

            $modifierTotal = 0;
            $modifiers = [];
            foreach ($line->modifiers as $mod) {
                $option = $mod->modifierOption;
                $group = $option?->modifierGroup;
                $adj = (int) ($option?->price_adjustment_cents ?? 0);
                $modifierTotal += $adj;
                $modifiers[] = [
                    'modifier_group_id' => $group?->id,
                    'modifier_option_id' => $option?->id,
                    'group_name_snapshot' => $group?->name ?? 'Unknown',
                    'option_name_snapshot' => $option?->name ?? 'Unknown',
                    'price_adjustment_cents' => $adj,
                    'quantity' => 1,
                    'total_adjustment_cents' => $adj,
                ];
            }

            $lineSubtotal = ($unitPrice + $modifierTotal) * max(1, (int) $line->quantity);

            $orderItem = OrderItem::query()->create([
                'public_id' => (string) \Illuminate\Support\Str::uuid(),
                'order_id' => $orderId,
                'menu_item_id' => $item?->id,
                'menu_item_variant_id' => $variant?->id,
                'item_name_snapshot' => $item?->name ?? 'Deleted item',
                'item_description_snapshot' => $item?->short_description,
                'item_image_snapshot' => $item?->image_urls,
                'variant_name_snapshot' => $variant?->name,
                'sku_snapshot' => $variant?->sku,
                'unit_price_cents' => $unitPrice,
                'quantity' => (int) $line->quantity,
                'line_subtotal_cents' => $lineSubtotal,
                'discount_cents' => 0,
                'line_total_cents' => $lineSubtotal,
                'preparation_minutes_snapshot' => $item?->preparation_minutes,
                'dietary_snapshot' => $item ? [
                    'is_vegetarian' => (bool) $item->is_vegetarian,
                    'is_vegan' => (bool) $item->is_vegan,
                    'is_gluten_free' => (bool) $item->is_gluten_free,
                    'is_halal' => (bool) $item->is_halal,
                ] : null,
                'allergen_snapshot' => $item?->allergens?->map(fn ($a) => [
                    'name' => $a->name, 'slug' => $a->slug, 'presence_type' => $a->pivot->presence_type ?? null,
                ])->values()->all(),
                'customer_instructions' => $line->special_instructions,
            ]);

            foreach ($modifiers as $m) {
                OrderItemModifier::query()->create(array_merge($m, [
                    'order_item_id' => $orderItem->id,
                ]));
            }

            return $orderItem;
        });
    }

    public function snapshotCommission(Restaurant $restaurant): array
    {
        if ($restaurant->isFirstParty()) {
            return ['rate' => 0, 'amount_cents' => 0];
        }

        $agreement = RestaurantCommissionAgreement::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', 'accepted')
            ->whereDate('effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', now()))
            ->orderByDesc('accepted_at')
            ->first();

        return [
            'rate' => $agreement ? (float) $agreement->commission_rate : 0,
            'amount_cents' => 0,
        ];
    }
}
