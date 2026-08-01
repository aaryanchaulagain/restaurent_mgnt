<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Models\MenuItem;
use App\Models\MenuItemInventory;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Services\Inventory\MenuItemInventoryService;
use App\Support\ApiResponse;
use App\Support\BusinessTypes;
use App\Support\RestaurantContext;
use Illuminate\Http\Request;

class RestaurantInventoryController
{
    public function __construct(
        private readonly MenuItemInventoryService $inventory,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $lowStockOnly = filter_var($request->query('low_stock', false), FILTER_VALIDATE_BOOLEAN);

        $query = MenuItemInventory::query()
            ->where('restaurant_id', $restaurantId)
            ->with(['menuItem', 'variant'])
            ->orderBy('menu_item_id')
            ->orderBy('variant_scope');

        if ($lowStockOnly) {
            $query->where('track_stock', true)
                ->whereNotNull('low_stock_threshold')
                ->whereColumn('quantity_on_hand', '<=', 'low_stock_threshold');
        }

        $rows = $query->get()->map(fn (MenuItemInventory $row) => $this->inventory->toPayload($row));

        return ApiResponse::success([
            'inventory_mode' => $this->inventoryMode($request),
            'inventories' => $rows,
        ]);
    }

    public function lowStock(Request $request)
    {
        $request->merge(['low_stock' => true]);

        return $this->index($request);
    }

    public function configure(Request $request, string $itemPublicId)
    {
        $restaurantId = RestaurantContext::id($request);
        $item = MenuItem::query()
            ->where('restaurant_id', $restaurantId)
            ->where('public_id', $itemPublicId)
            ->firstOrFail();

        $data = $request->validate([
            'variant_public_id' => ['nullable', 'uuid'],
            'track_stock' => ['sometimes', 'boolean'],
            'quantity_on_hand' => ['sometimes', 'integer', 'min:0'],
            'low_stock_threshold' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'force_unavailable' => ['sometimes', 'boolean'],
        ]);

        $row = $this->inventory->configure(
            $restaurantId,
            $item,
            $data,
            $this->businessType($request),
        );

        $this->audit($request, 'inventory.configured', $item, $restaurantId);

        return ApiResponse::success(['inventory' => $this->inventory->toPayload($row)]);
    }

    public function adjust(Request $request, string $itemPublicId)
    {
        $restaurantId = RestaurantContext::id($request);
        $item = MenuItem::query()
            ->where('restaurant_id', $restaurantId)
            ->where('public_id', $itemPublicId)
            ->firstOrFail();

        $data = $request->validate([
            'variant_public_id' => ['nullable', 'uuid'],
            'delta' => ['nullable', 'integer'],
            'set_quantity' => ['nullable', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $row = $this->inventory->adjust(
            $restaurantId,
            $item,
            $data['variant_public_id'] ?? null,
            array_key_exists('delta', $data) ? $data['delta'] : null,
            array_key_exists('set_quantity', $data) ? $data['set_quantity'] : null,
            $data['reason'] ?? null,
            $user,
            $this->businessType($request),
        );

        $this->audit($request, 'inventory.adjusted', $item, $restaurantId);

        return ApiResponse::success(['inventory' => $this->inventory->toPayload($row)]);
    }

    private function businessType(Request $request): string
    {
        $restaurant = RestaurantContext::restaurant($request)->loadMissing('business');

        return BusinessTypes::forRestaurant(
            $restaurant->business?->business_type,
            is_string($restaurant->vendor_type) ? $restaurant->vendor_type : null,
        );
    }

    private function inventoryMode(Request $request): string
    {
        return \App\Support\InventoryModes::forBusinessType($this->businessType($request));
    }

    private function audit(Request $request, string $action, MenuItem $item, int $restaurantId): void
    {
        /** @var User $user */
        $user = $request->user();
        $this->auditLogger->log($action, $user, $item, restaurantId: $restaurantId, request: $request);
    }
}
