<?php

namespace App\Services\Inventory;

use App\Models\InventoryStockAdjustment;
use App\Models\MenuItem;
use App\Models\MenuItemInventory;
use App\Models\MenuItemVariant;
use App\Models\User;
use App\Support\InventoryModes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MenuItemInventoryService
{
    /**
     * @return array{
     *   public_id: string,
     *   menu_item_public_id: string,
     *   menu_item_name: string,
     *   variant_public_id: string|null,
     *   variant_name: string|null,
     *   track_stock: bool,
     *   quantity_on_hand: int,
     *   low_stock_threshold: int|null,
     *   force_unavailable: bool,
     *   is_low_stock: bool,
     *   is_in_stock: bool
     * }
     */
    public function toPayload(MenuItemInventory $inventory): array
    {
        $inventory->loadMissing(['menuItem', 'variant']);

        return [
            'public_id' => $inventory->public_id,
            'menu_item_public_id' => $inventory->menuItem?->public_id,
            'menu_item_name' => $inventory->menuItem?->name,
            'variant_public_id' => $inventory->variant?->public_id,
            'variant_name' => $inventory->variant?->name,
            'track_stock' => $inventory->track_stock,
            'quantity_on_hand' => $inventory->quantity_on_hand,
            'low_stock_threshold' => $inventory->low_stock_threshold,
            'force_unavailable' => $inventory->force_unavailable,
            'is_low_stock' => $inventory->isLowStock(),
            'is_in_stock' => $inventory->isInStock(),
        ];
    }

    public function findOrCreate(
        int $restaurantId,
        MenuItem $item,
        ?MenuItemVariant $variant = null,
        bool $trackStock = true,
    ): MenuItemInventory {
        $variantId = $variant?->id;
        $scope = $variantId ?? 0;

        $existing = MenuItemInventory::query()
            ->where('restaurant_id', $restaurantId)
            ->where('menu_item_id', $item->id)
            ->where('variant_scope', $scope)
            ->first();

        if ($existing) {
            return $existing;
        }

        return MenuItemInventory::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurantId,
            'menu_item_id' => $item->id,
            'menu_item_variant_id' => $variantId,
            'variant_scope' => $scope,
            'track_stock' => $trackStock,
            'quantity_on_hand' => 0,
            'low_stock_threshold' => 5,
            'force_unavailable' => false,
        ]);
    }

    /**
     * @param  array{
     *   track_stock?: bool,
     *   quantity_on_hand?: int,
     *   low_stock_threshold?: int|null,
     *   force_unavailable?: bool,
     *   variant_public_id?: string|null
     * }  $data
     */
    public function configure(int $restaurantId, MenuItem $item, array $data, ?string $businessType): MenuItemInventory
    {
        if (! InventoryModes::tracksQuantity($businessType)) {
            throw ValidationException::withMessages([
                'inventory' => ['Counted inventory is not enabled for this business type.'],
            ]);
        }

        $variant = null;
        if (! empty($data['variant_public_id'])) {
            $variant = MenuItemVariant::query()
                ->where('restaurant_id', $restaurantId)
                ->where('menu_item_id', $item->id)
                ->where('public_id', $data['variant_public_id'])
                ->first();
            if (! $variant) {
                throw ValidationException::withMessages([
                    'variant_public_id' => ['Variant not found for this item.'],
                ]);
            }
        }

        return DB::transaction(function () use ($restaurantId, $item, $variant, $data) {
            $inventory = $this->findOrCreate($restaurantId, $item, $variant, $data['track_stock'] ?? true);
            $inventory = MenuItemInventory::query()->whereKey($inventory->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('track_stock', $data)) {
                $inventory->track_stock = (bool) $data['track_stock'];
            }
            if (array_key_exists('low_stock_threshold', $data)) {
                $inventory->low_stock_threshold = $data['low_stock_threshold'];
            }
            if (array_key_exists('force_unavailable', $data)) {
                $inventory->force_unavailable = (bool) $data['force_unavailable'];
            }
            if (array_key_exists('quantity_on_hand', $data) && is_int($data['quantity_on_hand'])) {
                if ($data['quantity_on_hand'] < 0) {
                    throw ValidationException::withMessages([
                        'quantity_on_hand' => ['Quantity cannot be negative.'],
                    ]);
                }
                $inventory->quantity_on_hand = $data['quantity_on_hand'];
            }
            $inventory->save();
            $this->syncAvailability($inventory);

            return $inventory->fresh(['menuItem', 'variant']);
        });
    }

    public function adjust(
        int $restaurantId,
        MenuItem $item,
        ?string $variantPublicId,
        ?int $delta,
        ?int $setQuantity,
        ?string $reason,
        ?User $user,
        ?string $businessType,
    ): MenuItemInventory {
        if (! InventoryModes::tracksQuantity($businessType)) {
            throw ValidationException::withMessages([
                'inventory' => ['Counted inventory is not enabled for this business type.'],
            ]);
        }

        if ($delta === null && $setQuantity === null) {
            throw ValidationException::withMessages([
                'delta' => ['Provide delta or set_quantity.'],
            ]);
        }
        if ($delta !== null && $setQuantity !== null) {
            throw ValidationException::withMessages([
                'delta' => ['Provide only one of delta or set_quantity.'],
            ]);
        }

        $variant = null;
        if ($variantPublicId) {
            $variant = MenuItemVariant::query()
                ->where('restaurant_id', $restaurantId)
                ->where('menu_item_id', $item->id)
                ->where('public_id', $variantPublicId)
                ->first();
            if (! $variant) {
                throw ValidationException::withMessages([
                    'variant_public_id' => ['Variant not found for this item.'],
                ]);
            }
        }

        return DB::transaction(function () use ($restaurantId, $item, $variant, $delta, $setQuantity, $reason, $user) {
            $inventory = $this->findOrCreate($restaurantId, $item, $variant);
            $inventory = MenuItemInventory::query()->whereKey($inventory->id)->lockForUpdate()->firstOrFail();

            if (! $inventory->track_stock) {
                throw ValidationException::withMessages([
                    'track_stock' => ['Stock tracking is disabled for this inventory row.'],
                ]);
            }

            $before = $inventory->quantity_on_hand;
            $after = $setQuantity !== null ? $setQuantity : ($before + (int) $delta);
            if ($after < 0) {
                throw ValidationException::withMessages([
                    'delta' => ['Adjustment would make quantity negative.'],
                ]);
            }

            $inventory->quantity_on_hand = $after;
            $inventory->save();

            InventoryStockAdjustment::query()->create([
                'public_id' => (string) Str::uuid(),
                'restaurant_id' => $restaurantId,
                'menu_item_inventory_id' => $inventory->id,
                'user_id' => $user?->id,
                'delta' => $after - $before,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'reason' => $reason,
            ]);

            $this->syncAvailability($inventory);

            return $inventory->fresh(['menuItem', 'variant']);
        });
    }

    public function syncAvailability(MenuItemInventory $inventory): void
    {
        $inventory->loadMissing(['menuItem', 'variant']);

        if ($inventory->force_unavailable) {
            if ($inventory->variant) {
                $inventory->variant->update(['is_available' => false]);
            } else {
                $inventory->menuItem?->update(['is_available' => false]);
            }

            return;
        }

        if (! $inventory->track_stock) {
            return;
        }

        $available = $inventory->quantity_on_hand > 0;
        if ($inventory->variant) {
            $inventory->variant->update(['is_available' => $available]);
            $this->syncItemAvailabilityFromVariants($inventory->menuItem);
        } else {
            $inventory->menuItem?->update(['is_available' => $available]);
        }
    }

    private function syncItemAvailabilityFromVariants(?MenuItem $item): void
    {
        if (! $item) {
            return;
        }

        $variants = MenuItemVariant::query()
            ->where('menu_item_id', $item->id)
            ->where('is_active', true)
            ->get();

        if ($variants->isEmpty()) {
            return;
        }

        $anyAvailable = $variants->contains(fn (MenuItemVariant $v) => $v->is_available);
        $item->update(['is_available' => $anyAvailable]);
    }

    /**
     * Public-safe availability (never exposes raw quantity).
     *
     * @param  Collection<int, MenuItemInventory>  $inventories
     * @return array{is_available: bool, availability_message: string|null, in_stock: bool}
     */
    public function publicItemAvailability(MenuItem $item, Collection $inventories): array
    {
        $itemLevel = $inventories->first(
            fn (MenuItemInventory $inv) => $inv->menu_item_id === $item->id && $inv->variant_scope === 0
        );

        $available = (bool) $item->is_available;
        if ($itemLevel?->force_unavailable) {
            $available = false;
        } elseif ($itemLevel?->track_stock) {
            $available = $available && $itemLevel->quantity_on_hand > 0;
        }

        return [
            'is_available' => $available,
            'availability_message' => $available ? null : 'Sold out',
            'in_stock' => $available,
        ];
    }

    /**
     * @param  Collection<int, MenuItemInventory>  $inventories
     */
    public function publicVariantAvailable(MenuItemVariant $variant, Collection $inventories): bool
    {
        if (! $variant->is_active || ! $variant->is_available) {
            return false;
        }

        $row = $inventories->first(
            fn (MenuItemInventory $inv) => $inv->menu_item_variant_id === $variant->id
        );

        if ($row?->force_unavailable) {
            return false;
        }
        if ($row?->track_stock) {
            return $row->quantity_on_hand > 0;
        }

        return true;
    }
}
