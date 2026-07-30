<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\MenuItem;
use App\Models\MenuItemVariant;
use App\Models\ModifierOption;
use App\Models\Offer;
use App\Models\OfferTarget;
use App\Models\Restaurant;
use Illuminate\Support\Collection;

class CartPricingService
{
    /**
     * @return array{
     *   subtotal_cents: int,
     *   discount_cents: int,
     *   tax_cents: int,
     *   service_fee_cents: int,
     *   delivery_fee_cents: int|null,
     *   total_before_delivery_cents: int,
     *   currency: string,
     *   warnings: array<int, array{code: string, message: string, cart_item_public_id?: string}>,
     *   minimum_order_cents: int,
     *   minimum_order_met: bool,
     *   lines: array<int, array<string, mixed>>
     * }
     */
    public function calculate(Cart $cart, bool $detectChanges = true): array
    {
        $cart->load(['items.menuItem', 'items.variant', 'items.modifiers', 'restaurant']);
        $restaurant = $cart->restaurant;
        $currency = $cart->currency ?: ($restaurant->currency ?? 'AUD');
        $warnings = [];
        $lines = [];
        $subtotal = 0;

        foreach ($cart->items as $line) {
            $priced = $this->priceLine($line, $detectChanges, $warnings);
            $lines[] = $priced;
            $subtotal += $priced['line_total_cents'];
        }

        $discount = $this->restaurantDiscount($restaurant, $cart, $subtotal);
        $afterDiscount = max(0, $subtotal - $discount);
        $taxRate = config('checkout.estimated_tax_rate');
        $tax = (int) round($afterDiscount * (float) $taxRate);
        $serviceFee = (int) config('checkout.estimated_service_fee_cents');
        $totalBeforeDelivery = max(0, $afterDiscount + $tax + $serviceFee);
        $minOrder = (int) ($restaurant->minimum_order_cents ?? 0);

        if ($minOrder > 0 && $afterDiscount < $minOrder) {
            $warnings[] = [
                'code' => 'MINIMUM_ORDER_NOT_MET',
                'message' => 'Minimum order not met.',
            ];
        }

        return [
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'tax_cents' => $tax,
            'service_fee_cents' => $serviceFee,
            'delivery_fee_cents' => null,
            'total_before_delivery_cents' => $totalBeforeDelivery,
            'currency' => $currency,
            'warnings' => $warnings,
            'minimum_order_cents' => $minOrder,
            'minimum_order_met' => $minOrder === 0 || $afterDiscount >= $minOrder,
            'lines' => $lines,
        ];
    }

    /**
     * @param  array<int, array{code: string, message: string, cart_item_public_id?: string}>  $warnings
     * @return array<string, mixed>
     */
    private function priceLine(CartItem $line, bool $detectChanges, array &$warnings): array
    {
        $item = $line->menuItem;
        if (! $item || ! $item->is_active) {
            $warnings[] = [
                'code' => 'ITEM_UNAVAILABLE',
                'message' => 'An item is no longer available.',
                'cart_item_public_id' => $line->public_id,
            ];
        }
        if ($item && ! $item->is_available) {
            $warnings[] = [
                'code' => 'ITEM_UNAVAILABLE',
                'message' => "{$item->name} is sold out.",
                'cart_item_public_id' => $line->public_id,
            ];
        }

        $unit = $this->unitPriceCents($item, $line->variant);
        $modifierTotal = 0;
        foreach ($line->modifiers as $mod) {
            $option = ModifierOption::query()->find($mod->modifier_option_id);
            if ($option && $option->is_active && $option->is_available) {
                $modifierTotal += (int) $option->price_adjustment_cents;
                if ($detectChanges && (int) $mod->price_adjustment_snapshot_cents !== (int) $option->price_adjustment_cents) {
                    $warnings[] = [
                        'code' => 'MODIFIER_PRICE_CHANGED',
                        'message' => 'A modifier price has changed.',
                        'cart_item_public_id' => $line->public_id,
                    ];
                }
            }
        }

        if ($detectChanges && $line->unit_price_snapshot_cents > 0 && $line->unit_price_snapshot_cents !== $unit) {
            $warnings[] = [
                'code' => 'ITEM_PRICE_CHANGED',
                'message' => 'An item price has changed.',
                'cart_item_public_id' => $line->public_id,
            ];
        }

        $lineTotal = ($unit + $modifierTotal) * max(1, (int) $line->quantity);

        return [
            'cart_item_public_id' => $line->public_id,
            'name' => $item?->name,
            'quantity' => (int) $line->quantity,
            'unit_price_cents' => $unit,
            'modifier_total_cents' => $modifierTotal,
            'line_total_cents' => $lineTotal,
        ];
    }

    private function unitPriceCents(?MenuItem $item, ?MenuItemVariant $variant): int
    {
        if ($variant && $variant->is_active) {
            return (int) $variant->price_cents;
        }

        return (int) ($item?->base_price_cents ?? 0);
    }

    private function restaurantDiscount(Restaurant $restaurant, Cart $cart, int $subtotal): int
    {
        $offers = Offer::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->with('targets')
            ->get();

        $discount = 0;
        foreach ($offers as $offer) {
            if ($offer->minimum_order_cents && $subtotal < (int) $offer->minimum_order_cents) {
                continue;
            }
            if (! $this->offerAppliesToCart($offer, $cart)) {
                continue;
            }
            $d = match ($offer->offer_type) {
                'percentage' => (int) round($subtotal * ((float) $offer->value / 100)),
                'fixed_amount' => (int) round((float) $offer->value * 100),
                default => 0,
            };
            if ($offer->maximum_discount_cents) {
                $d = min($d, (int) $offer->maximum_discount_cents);
            }
            $discount = max($discount, $d);
        }

        return min($discount, $subtotal);
    }

    private function offerAppliesToCart(Offer $offer, Cart $cart): bool
    {
        /** @var Collection<int, OfferTarget> $targets */
        $targets = $offer->targets;
        if ($targets->isEmpty()) {
            return true;
        }

        foreach ($targets as $target) {
            if ($target->target_type === 'restaurant') {
                return true;
            }
        }

        $itemIds = $cart->items->pluck('menu_item_id');
        foreach ($targets as $target) {
            if ($target->target_type === 'menu_item' && $itemIds->contains($target->target_id)) {
                return true;
            }
            if ($target->target_type === 'menu_category') {
                $catIds = MenuItem::query()->whereIn('id', $itemIds)->pluck('menu_category_id');
                if ($catIds->contains($target->target_id)) {
                    return true;
                }
            }
            if ($target->target_type === 'menu' && $target->target_id) {
                $menuIds = MenuItem::query()->whereIn('id', $itemIds)->pluck('menu_id');
                if ($menuIds->contains($target->target_id)) {
                    return true;
                }
            }
        }

        return false;
    }
}
