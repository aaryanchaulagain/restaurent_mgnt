<?php

namespace App\Services\PublicCatalog;

use App\Models\Allergen;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemInventory;
use App\Models\Restaurant;
use App\Services\Inventory\MenuItemInventoryService;
use App\Services\Media\PublicImageService;
use App\Support\MenuItemTypeDetails;

/**
 * Shared public catalogue payload for restaurant-slug and business/branch menu routes.
 */
class PublicCatalogueService
{
    public function __construct(
        private readonly MenuItemInventoryService $inventory,
        private readonly PublicImageService $images,
    ) {}

    /**
     * @return array{
     *   restaurant: array{slug: string, public_id: string},
     *   menus: mixed,
     *   categories: list<array{public_id: string, name: string, description: mixed}>,
     *   items: mixed
     * }
     */
    public function menuPayload(Restaurant $restaurant): array
    {
        $menus = Menu::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->get(['public_id', 'name', 'description', 'is_default']);

        $categories = MenuCategory::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $items = MenuItem::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->with([
                'category',
                'variants' => fn ($q) => $q->where('is_active', true),
                'allergens',
                'modifierGroups' => fn ($q) => $q->where('is_active', true)->with([
                    'options' => fn ($oq) => $oq->where('is_active', true),
                ]),
            ])
            ->orderBy('sort_order')
            ->get();

        $inventories = MenuItemInventory::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('menu_item_id', $items->pluck('id'))
            ->get();

        $mappedItems = $items->map(function (MenuItem $item) use ($inventories) {
            $availability = $this->inventory->publicItemAvailability($item, $inventories);

            return [
                'public_id' => $item->public_id,
                'menu_category_public_id' => $item->category?->public_id,
                'name' => $item->name,
                'slug' => $item->slug,
                'short_description' => $item->short_description,
                'description' => $item->description,
                'image' => $this->images->toPublicPayload($item->image_urls, 'item'),
                'base_price_cents' => $item->base_price_cents,
                'compare_at_price_cents' => $item->compare_at_price_cents,
                'preparation_minutes' => $item->preparation_minutes,
                'is_available' => $availability['is_available'],
                'availability_message' => $availability['availability_message'],
                'in_stock' => $availability['in_stock'],
                'dietary' => [
                    'is_vegetarian' => $item->is_vegetarian,
                    'is_vegan' => $item->is_vegan,
                    'is_gluten_free' => $item->is_gluten_free,
                    'is_halal' => $item->is_halal,
                ],
                'spice_level' => $item->spice_level,
                'type_details' => MenuItemTypeDetails::forPublic($item->type_details),
                'variants' => $item->variants
                    ->filter(fn ($v) => $this->inventory->publicVariantAvailable($v, $inventories))
                    ->values()
                    ->map(fn ($v) => [
                        'public_id' => $v->public_id,
                        'name' => $v->name,
                        'price_cents' => $v->price_cents,
                        'is_default' => $v->is_default,
                    ]),
                'modifier_groups' => $item->modifierGroups->map(fn ($g) => [
                    'public_id' => $g->public_id,
                    'name' => $g->name,
                    'selection_type' => $g->selection_type,
                    'minimum_selections' => $g->minimum_selections,
                    'maximum_selections' => $g->maximum_selections,
                    'is_required' => $g->is_required,
                    'options' => $g->options->where('is_active', true)->where('is_available', true)->values()->map(fn ($o) => [
                        'public_id' => $o->public_id,
                        'name' => $o->name,
                        'price_adjustment_cents' => $o->price_adjustment_cents,
                        'is_default' => $o->is_default,
                    ]),
                ]),
                'allergens' => $item->allergens->map(fn (Allergen $a) => [
                    'slug' => $a->slug,
                    'name' => $a->name,
                    'presence_type' => $a->pivot->presence_type,
                ]),
            ];
        });

        return [
            'restaurant' => [
                'slug' => $restaurant->slug,
                'public_id' => $restaurant->public_id,
            ],
            'menus' => $menus,
            'categories' => $categories->map(fn ($c) => [
                'public_id' => $c->public_id,
                'name' => $c->name,
                'description' => $c->description,
            ])->values(),
            'items' => $mappedItems->values(),
        ];
    }
}
