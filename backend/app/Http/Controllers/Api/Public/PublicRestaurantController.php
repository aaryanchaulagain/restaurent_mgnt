<?php

namespace App\Http\Controllers\Api\Public;

use App\Enums\Partner\RestaurantStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Public\PublicRestaurantResource;
use App\Models\Allergen;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Offer;
use App\Models\Restaurant;
use App\Models\RestaurantOpeningHour;
use App\Services\Media\PublicImageService;
use App\Services\Restaurant\RestaurantOpenStatusService;
use App\Support\ApiResponse;
use App\Support\MenuItemTypeDetails;
use App\Support\VendorTypes;
use Illuminate\Http\Request;

class PublicRestaurantController extends Controller
{
    public function __construct(
        private readonly RestaurantOpenStatusService $openStatus,
        private readonly PublicImageService $images,
    ) {}

    public function index(Request $request)
    {
        $query = Restaurant::query()
            ->where('status', RestaurantStatus::Active)
            ->whereNotNull('published_at')
            ->whereNull('suspended_at')
            ->with('cuisines');

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(function ($q) use ($term) {
                $q->where('trading_name', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('slug', 'like', $term);
            });
        }
        if ($request->filled('cuisine_slug')) {
            $slug = $request->string('cuisine_slug');
            $query->whereHas('cuisines', fn ($q) => $q->where('slug', $slug));
        }
        if ($request->filled('ownership_type')) {
            $ownership = (string) $request->string('ownership_type');
            if (in_array($ownership, ['first_party', 'third_party'], true)) {
                $query->where('ownership_type', $ownership);
            }
        }
        if ($request->filled('vendor_type')) {
            $vendorType = (string) $request->string('vendor_type');
            if (in_array($vendorType, VendorTypes::all(), true)) {
                $query->where('vendor_type', $vendorType);
            }
        }
        if ($request->boolean('pickup')) {
            $query->where('pickup_enabled', true);
        }
        if ($request->boolean('delivery')) {
            $query->where('restaurant_delivery_enabled', true);
        }
        if ($request->filled('price_level')) {
            $query->where('price_level', $request->string('price_level'));
        }

        $paginated = $query
            ->orderByRaw("CASE WHEN ownership_type = 'first_party' THEN 0 ELSE 1 END")
            ->orderByDesc('published_at')
            ->paginate(min(50, (int) $request->input('per_page', 40)));

        $items = $paginated->getCollection()->map(function (Restaurant $r) {
            return (new PublicRestaurantResource($r, $this->openStatus->isOpenNow($r), $this->images))->resolve();
        });
        $paginated->setCollection($items);

        if ($request->boolean('open_now')) {
            $filtered = $items->filter(fn ($row) => ($row['is_open'] ?? $row['open_now'] ?? false))->values();
            $paginated->setCollection($filtered);
        }

        return ApiResponse::success(
            data: ['restaurants' => $paginated->items()],
            meta: [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        );
    }

    public function cuisines()
    {
        $cuisines = \App\Models\Cuisine::query()
            ->where('is_active', true)
            ->whereHas('restaurants', function ($q) {
                $q->where('status', RestaurantStatus::Active)
                    ->whereNotNull('published_at')
                    ->whereNull('suspended_at');
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['name', 'slug']);

        return ApiResponse::success([
            'cuisines' => $cuisines->map(fn ($c) => [
                'name' => $c->name,
                'slug' => $c->slug,
            ])->values(),
        ]);
    }

    public function platformRestaurant()
    {
        $slug = config('suvakamana.platform_restaurant_slug', 'suvakamana-restaurant');

        $restaurant = Restaurant::query()
            ->where('slug', $slug)
            ->where('status', RestaurantStatus::Active)
            ->whereNotNull('published_at')
            ->whereNull('suspended_at')
            ->with('cuisines')
            ->first();

        if (! $restaurant) {
            return ApiResponse::error('Featured partner not found.', 404);
        }

        $items = MenuItem::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->with(['variants' => fn ($q) => $q->where('is_active', true), 'modifierGroups' => fn ($q) => $q->where('is_active', true)->with(['options' => fn ($oq) => $oq->where('is_active', true)])])
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at')
            ->orderBy('sort_order')
            ->limit(12)
            ->get()
            ->map(fn (MenuItem $item) => [
                'public_id' => $item->public_id,
                'name' => $item->name,
                'slug' => $item->slug,
                'short_description' => $item->short_description,
                'image' => $this->images->toPublicPayload($item->image_urls, 'item'),
                'base_price_cents' => $item->base_price_cents,
                'compare_at_price_cents' => $item->compare_at_price_cents,
                'is_available' => $item->is_available,
                    'is_featured' => $item->is_featured,
                    'dietary' => [
                        'is_vegetarian' => $item->is_vegetarian,
                        'is_vegan' => $item->is_vegan,
                        'is_gluten_free' => $item->is_gluten_free,
                        'is_halal' => $item->is_halal,
                    ],
                    'type_details' => MenuItemTypeDetails::forPublic($item->type_details),
                    'variants' => $item->variants->map(fn ($v) => [
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
                    'options' => $g->options->map(fn ($o) => [
                        'public_id' => $o->public_id,
                        'name' => $o->name,
                        'price_adjustment_cents' => $o->price_adjustment_cents,
                        'is_default' => $o->is_default,
                    ]),
                ]),
            ]);

        $offers = Offer::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->get()
            ->map(fn (Offer $o) => [
                'public_id' => $o->public_id,
                'name' => $o->name,
                'description' => $o->description,
                'offer_type' => $o->offer_type,
                'value' => $o->value,
            ]);

        return ApiResponse::success([
            'restaurant' => (new PublicRestaurantResource(
                $restaurant,
                $this->openStatus->isOpenNow($restaurant),
                $this->images,
                $this->todayHours($restaurant),
                $this->openStatus->nextOpeningTime($restaurant),
            ))->resolve(),
            'featured_items' => $items,
            'active_offers' => $offers,
        ]);
    }

    public function show(string $slug)
    {
        $restaurant = Restaurant::query()
            ->where('slug', $slug)
            ->where('status', RestaurantStatus::Active)
            ->whereNotNull('published_at')
            ->whereNull('suspended_at')
            ->with('cuisines')
            ->firstOrFail();

        $offers = Offer::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->get()
            ->map(fn (Offer $o) => [
                'public_id' => $o->public_id,
                'name' => $o->name,
                'description' => $o->description,
                'offer_type' => $o->offer_type,
                'value' => $o->value,
                'minimum_order_cents' => $o->minimum_order_cents,
            ]);

        return ApiResponse::success([
            'restaurant' => (new PublicRestaurantResource(
                $restaurant,
                $this->openStatus->isOpenNow($restaurant),
                $this->images,
                $this->todayHours($restaurant),
                $this->openStatus->nextOpeningTime($restaurant),
            ))->resolve(),
            'active_offers' => $offers,
            'allergen_disclaimer' => 'Menu allergen labels are provided by the restaurant. If you have a severe allergy, contact the restaurant directly before ordering.',
        ]);
    }

    public function menu(string $slug)
    {
        $restaurant = Restaurant::query()
            ->where('slug', $slug)
            ->where('status', RestaurantStatus::Active)
            ->whereNotNull('published_at')
            ->whereNull('suspended_at')
            ->firstOrFail();

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
            ->with(['category', 'variants' => fn ($q) => $q->where('is_active', true), 'allergens', 'modifierGroups' => fn ($q) => $q->where('is_active', true)->with(['options' => fn ($oq) => $oq->where('is_active', true)])])
            ->orderBy('sort_order')
            ->get()
            ->map(function (MenuItem $item) {
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
                    'is_available' => $item->is_available,
                    'availability_message' => $item->is_available ? null : 'Sold out',
                    'dietary' => [
                        'is_vegetarian' => $item->is_vegetarian,
                        'is_vegan' => $item->is_vegan,
                        'is_gluten_free' => $item->is_gluten_free,
                        'is_halal' => $item->is_halal,
                    ],
                    'spice_level' => $item->spice_level,
                    'type_details' => MenuItemTypeDetails::forPublic($item->type_details),
                    'variants' => $item->variants->where('is_active', true)->where('is_available', true)->values()->map(fn ($v) => [
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

        return ApiResponse::success([
            'restaurant' => ['slug' => $restaurant->slug, 'public_id' => $restaurant->public_id],
            'menus' => $menus,
            'categories' => $categories->map(fn ($c) => [
                'public_id' => $c->public_id,
                'name' => $c->name,
                'description' => $c->description,
            ]),
            'items' => $items,
        ]);
    }

    private function todayHours(Restaurant $restaurant): ?array
    {
        $tz = $restaurant->timezone ?: config('restaurant.default_timezone');
        $day = (int) now($tz)->dayOfWeek;
        $periods = RestaurantOpeningHour::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('day_of_week', $day)
            ->where('is_closed', false)
            ->get();

        if ($periods->isEmpty()) {
            return null;
        }

        return $periods->map(fn ($p) => [
            'opens_at' => $p->opens_at,
            'closes_at' => $p->closes_at,
            'service_type' => $p->service_type,
        ])->all();
    }
}
