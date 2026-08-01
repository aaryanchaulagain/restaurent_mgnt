<?php

namespace App\Services\Inventory;

use App\Exceptions\OrderApiException;
use App\Models\InventoryReservation;
use App\Models\InventoryStockAdjustment;
use App\Models\MenuItemInventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Support\BusinessTypes;
use App\Support\InventoryModes;
use App\Support\OrderErrorResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InventoryReservationService
{
    public function __construct(
        private readonly MenuItemInventoryService $inventoryService,
    ) {}

    public function activeReservedQuantity(int $inventoryId): int
    {
        return (int) InventoryReservation::query()
            ->where('menu_item_inventory_id', $inventoryId)
            ->where('status', InventoryReservation::STATUS_ACTIVE)
            ->sum('quantity');
    }

    /**
     * @param  Collection<int, int>|null  $reservedByInventoryId  preloaded map inventory_id => reserved qty
     */
    public function availableQuantity(MenuItemInventory $inventory, ?Collection $reservedByInventoryId = null): int
    {
        if (! $inventory->track_stock) {
            return PHP_INT_MAX;
        }

        $reserved = $reservedByInventoryId !== null
            ? (int) ($reservedByInventoryId[$inventory->id] ?? 0)
            : $this->activeReservedQuantity($inventory->id);

        return max(0, $inventory->quantity_on_hand - $reserved);
    }

    /**
     * Reserve stock for a newly placed order. Idempotent per order.
     * No-op for boolean inventory modes or items without tracked inventory.
     */
    public function reserveForOrder(Order $order): void
    {
        $order->loadMissing(['items', 'restaurant.business']);

        if (! $this->tracksQuantityForRestaurant($order->restaurant)) {
            return;
        }

        $existing = InventoryReservation::query()
            ->where('order_id', $order->id)
            ->get();

        if ($existing->contains(fn (InventoryReservation $r) => $r->status === InventoryReservation::STATUS_ACTIVE
            || $r->status === InventoryReservation::STATUS_CONSUMED)) {
            return;
        }

        // Re-reserve after full release (e.g. payment retry).
        if ($existing->isNotEmpty() && $existing->every(fn (InventoryReservation $r) => $r->status === InventoryReservation::STATUS_RELEASED)) {
            InventoryReservation::query()->where('order_id', $order->id)->delete();
        }

        $lines = $order->items;
        if ($lines->isEmpty()) {
            return;
        }

        // Lock inventory rows in stable id order to avoid deadlocks.
        $resolved = [];
        foreach ($lines as $line) {
            $inventory = $this->resolveTrackedInventory($order->restaurant_id, $line);
            if ($inventory) {
                $resolved[] = ['line' => $line, 'inventory_id' => $inventory->id];
            }
        }

        usort($resolved, fn ($a, $b) => $a['inventory_id'] <=> $b['inventory_id']);

        $neededByInventory = [];
        foreach ($resolved as $row) {
            $id = $row['inventory_id'];
            $neededByInventory[$id] = ($neededByInventory[$id] ?? 0) + (int) $row['line']->quantity;
        }

        $locked = [];
        foreach (array_keys($neededByInventory) as $inventoryId) {
            $locked[$inventoryId] = MenuItemInventory::query()->whereKey($inventoryId)->lockForUpdate()->firstOrFail();
        }

        foreach ($neededByInventory as $inventoryId => $needed) {
            $inventory = $locked[$inventoryId];
            $available = $this->availableQuantity($inventory);
            if ($available < $needed) {
                throw new OrderApiException(
                    'INSUFFICIENT_STOCK',
                    OrderErrorResponse::messageForCode('INSUFFICIENT_STOCK'),
                    422,
                );
            }
        }

        foreach ($resolved as $row) {
            /** @var OrderItem $line */
            $line = $row['line'];
            $inventory = $locked[$row['inventory_id']];

            InventoryReservation::query()->create([
                'public_id' => (string) Str::uuid(),
                'restaurant_id' => $order->restaurant_id,
                'order_id' => $order->id,
                'order_item_id' => $line->id,
                'menu_item_inventory_id' => $inventory->id,
                'menu_item_id' => $line->menu_item_id,
                'menu_item_variant_id' => $line->menu_item_variant_id,
                'quantity' => (int) $line->quantity,
                'status' => InventoryReservation::STATUS_ACTIVE,
                'reserved_at' => now(),
                'expires_at' => $order->expires_at,
            ]);
        }

        foreach ($locked as $inventory) {
            $this->inventoryService->syncAvailability($inventory->fresh());
        }
    }

    /**
     * Consume active reservations and decrement on-hand stock. Idempotent.
     */
    public function consumeForOrder(Order $order): void
    {
        $reservations = InventoryReservation::query()
            ->where('order_id', $order->id)
            ->orderBy('menu_item_inventory_id')
            ->lockForUpdate()
            ->get();

        if ($reservations->isEmpty()) {
            return;
        }

        if ($reservations->every(fn (InventoryReservation $r) => $r->status === InventoryReservation::STATUS_CONSUMED)) {
            return;
        }

        foreach ($reservations as $reservation) {
            if ($reservation->status !== InventoryReservation::STATUS_ACTIVE) {
                continue;
            }

            $inventory = MenuItemInventory::query()
                ->whereKey($reservation->menu_item_inventory_id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = $inventory->quantity_on_hand;
            $after = $before - $reservation->quantity;
            if ($after < 0) {
                throw ValidationException::withMessages([
                    'inventory' => ['Cannot consume reservation: on-hand stock is insufficient.'],
                ]);
            }

            $inventory->quantity_on_hand = $after;
            $inventory->save();

            InventoryStockAdjustment::query()->create([
                'public_id' => (string) Str::uuid(),
                'restaurant_id' => $inventory->restaurant_id,
                'menu_item_inventory_id' => $inventory->id,
                'user_id' => null,
                'delta' => $after - $before,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'reason' => 'order.consumed:'.$order->public_id,
            ]);

            $reservation->update([
                'status' => InventoryReservation::STATUS_CONSUMED,
                'consumed_at' => now(),
            ]);

            $this->inventoryService->syncAvailability($inventory->fresh());
        }
    }

    /**
     * Release active reservations without changing on-hand. Idempotent.
     */
    public function releaseForOrder(Order $order, ?string $reason = null): void
    {
        $reservations = InventoryReservation::query()
            ->where('order_id', $order->id)
            ->orderBy('menu_item_inventory_id')
            ->lockForUpdate()
            ->get();

        if ($reservations->isEmpty()) {
            return;
        }

        if ($reservations->every(fn (InventoryReservation $r) => in_array($r->status, [
            InventoryReservation::STATUS_RELEASED,
            InventoryReservation::STATUS_CONSUMED,
        ], true))) {
            return;
        }

        $touchedInventoryIds = [];
        foreach ($reservations as $reservation) {
            if ($reservation->status !== InventoryReservation::STATUS_ACTIVE) {
                continue;
            }

            $reservation->update([
                'status' => InventoryReservation::STATUS_RELEASED,
                'released_at' => now(),
                'release_reason' => $reason,
            ]);
            $touchedInventoryIds[$reservation->menu_item_inventory_id] = true;
        }

        foreach (array_keys($touchedInventoryIds) as $inventoryId) {
            $inventory = MenuItemInventory::query()->find($inventoryId);
            if ($inventory) {
                $this->inventoryService->syncAvailability($inventory);
            }
        }
    }

    /**
     * Release active reservations past expires_at.
     *
     * @return int number of reservations released
     */
    public function releaseExpired(?\DateTimeInterface $now = null): int
    {
        $now = $now ?? now();
        $orderIds = InventoryReservation::query()
            ->where('status', InventoryReservation::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->distinct()
            ->pluck('order_id');

        $count = 0;
        foreach ($orderIds as $orderId) {
            DB::transaction(function () use ($orderId, &$count) {
                $order = Order::query()->lockForUpdate()->find($orderId);
                if (! $order) {
                    return;
                }
                $before = InventoryReservation::query()
                    ->where('order_id', $order->id)
                    ->where('status', InventoryReservation::STATUS_ACTIVE)
                    ->count();
                $this->releaseForOrder($order, 'reservation_expired');
                $count += $before;
            });
        }

        return $count;
    }

    /**
     * @return array{active_without_order: int, active_past_expiry: int, consumed_with_active_sibling: int}
     */
    public function integrityReport(): array
    {
        $activeWithoutOrder = InventoryReservation::query()
            ->where('status', InventoryReservation::STATUS_ACTIVE)
            ->whereDoesntHave('order')
            ->count();

        $activePastExpiry = InventoryReservation::query()
            ->where('status', InventoryReservation::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->count();

        $consumedWithActive = InventoryReservation::query()
            ->where('status', InventoryReservation::STATUS_CONSUMED)
            ->whereIn('order_id', function ($q) {
                $q->select('order_id')
                    ->from('inventory_reservations')
                    ->where('status', InventoryReservation::STATUS_ACTIVE);
            })
            ->count();

        return [
            'active_without_order' => $activeWithoutOrder,
            'active_past_expiry' => $activePastExpiry,
            'consumed_with_active_sibling' => $consumedWithActive,
        ];
    }

    private function tracksQuantityForRestaurant(?Restaurant $restaurant): bool
    {
        if (! $restaurant) {
            return false;
        }

        $type = BusinessTypes::forRestaurant(
            $restaurant->business?->business_type,
            is_string($restaurant->vendor_type) ? $restaurant->vendor_type : null,
        );

        return InventoryModes::tracksQuantity($type);
    }

    private function resolveTrackedInventory(int $restaurantId, OrderItem $line): ?MenuItemInventory
    {
        if (! $line->menu_item_id) {
            return null;
        }

        if ($line->menu_item_variant_id) {
            $variantRow = MenuItemInventory::query()
                ->where('restaurant_id', $restaurantId)
                ->where('menu_item_id', $line->menu_item_id)
                ->where('variant_scope', $line->menu_item_variant_id)
                ->where('track_stock', true)
                ->first();
            if ($variantRow) {
                return $variantRow;
            }
        }

        return MenuItemInventory::query()
            ->where('restaurant_id', $restaurantId)
            ->where('menu_item_id', $line->menu_item_id)
            ->where('variant_scope', 0)
            ->where('track_stock', true)
            ->first();
    }
}
