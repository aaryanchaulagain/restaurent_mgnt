<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\Allergen;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemVariant;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Support\ApiResponse;
use App\Support\RestaurantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RestaurantMenuController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function listMenus(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $menus = Menu::query()->where('restaurant_id', $restaurantId)->orderBy('sort_order')->get();

        return ApiResponse::success(['menus' => $menus]);
    }

    public function storeMenu(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'inactive', 'archived'])],
            'is_default' => ['sometimes', 'boolean'],
        ]);
        if (! empty($data['is_default'])) {
            Menu::query()->where('restaurant_id', $restaurantId)->update(['is_default' => false]);
        }
        $menu = Menu::query()->create(array_merge($data, [
            'restaurant_id' => $restaurantId,
            'public_id' => (string) Str::uuid(),
            'status' => $data['status'] ?? 'draft',
        ]));
        $this->audit($request, 'menu.created', $menu, $restaurantId);

        return ApiResponse::success(['menu' => $menu], status: 201);
    }

    public function defaultMenuId(Request $request): int
    {
        $restaurantId = RestaurantContext::id($request);
        $menu = Menu::query()->where('restaurant_id', $restaurantId)->where('is_default', true)->first();
        if ($menu) {
            return $menu->id;
        }
        $menu = Menu::query()->create([
            'restaurant_id' => $restaurantId,
            'public_id' => (string) Str::uuid(),
            'name' => 'Main menu',
            'status' => 'active',
            'is_default' => true,
        ]);
        $this->audit($request, 'menu.created', $menu, $restaurantId);

        return $menu->id;
    }

    public function listCategories(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $categories = MenuCategory::query()
            ->where('restaurant_id', $restaurantId)
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success(['categories' => $categories]);
    }

    public function storeCategory(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $menuId = $this->defaultMenuId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $category = MenuCategory::query()->create(array_merge($data, [
            'restaurant_id' => $restaurantId,
            'menu_id' => $menuId,
            'public_id' => (string) Str::uuid(),
            'sort_order' => (int) MenuCategory::query()->where('restaurant_id', $restaurantId)->max('sort_order') + 1,
        ]));
        $this->audit($request, 'menu.category_created', $category, $restaurantId);

        return ApiResponse::success(['category' => $category], status: 201);
    }

    public function updateCategory(Request $request, string $publicId)
    {
        $restaurantId = RestaurantContext::id($request);
        $category = MenuCategory::query()->where('restaurant_id', $restaurantId)->where('public_id', $publicId)->firstOrFail();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
        $category->update($data);
        $this->audit($request, 'menu.category_updated', $category, $restaurantId);

        return ApiResponse::success(['category' => $category->fresh()]);
    }

    public function deleteCategory(Request $request, string $publicId)
    {
        $restaurantId = RestaurantContext::id($request);
        $category = MenuCategory::query()->where('restaurant_id', $restaurantId)->where('public_id', $publicId)->firstOrFail();
        $category->delete();
        $this->audit($request, 'menu.category_archived', $category, $restaurantId);

        return ApiResponse::success(message: 'Archived.');
    }

    public function reorderCategories(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['uuid'],
        ]);
        foreach ($data['order'] as $index => $publicId) {
            MenuCategory::query()
                ->where('restaurant_id', $restaurantId)
                ->where('public_id', $publicId)
                ->update(['sort_order' => $index]);
        }
        $this->audit($request, 'menu.category_reordered', null, $restaurantId);

        return ApiResponse::success(message: 'Reordered.');
    }

    public function listItems(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $query = MenuItem::query()->where('restaurant_id', $restaurantId)->with(['variants', 'allergens']);
        if ($request->filled('category_public_id')) {
            $cat = MenuCategory::query()->where('restaurant_id', $restaurantId)->where('public_id', $request->string('category_public_id'))->first();
            if ($cat) {
                $query->where('menu_category_id', $cat->id);
            }
        }
        $items = $query->orderBy('sort_order')->get()->map(fn (MenuItem $item) => $this->itemPayload($item, true));

        return ApiResponse::success(['items' => $items]);
    }

    public function storeItem(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $menuId = $this->defaultMenuId($request);
        $data = $request->validate([
            'menu_category_public_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'base_price_cents' => ['required', 'integer', 'min:0'],
            'compare_at_price_cents' => ['nullable', 'integer', 'min:0'],
            'cost_price_cents' => ['nullable', 'integer', 'min:0'],
            'preparation_minutes' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_vegetarian' => ['sometimes', 'boolean'],
            'is_vegan' => ['sometimes', 'boolean'],
            'is_gluten_free' => ['sometimes', 'boolean'],
            'is_halal' => ['sometimes', 'boolean'],
            'spice_level' => ['nullable', Rule::in(config('restaurant.spice_levels'))],
        ]);
        $category = MenuCategory::query()
            ->where('restaurant_id', $restaurantId)
            ->where('public_id', $data['menu_category_public_id'])
            ->first();
        if (! $category) {
            abort(ApiResponse::error(
                'Category not found for this restaurant. Pick or create a category again.',
                404,
                errors: ['menu_category_public_id' => ['Category not found for this restaurant. Pick or create a category again.']],
                code: 'CATEGORY_NOT_FOUND',
            ));
        }
        if ($data['compare_at_price_cents'] ?? null) {
            if ($data['compare_at_price_cents'] <= $data['base_price_cents']) {
                throw ValidationException::withMessages(['compare_at_price_cents' => ['Compare-at price must exceed base price.']]);
            }
        }
        $slug = $this->uniqueItemSlug($restaurantId, Str::slug($data['name']) ?: 'item');
        $item = MenuItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurantId,
            'menu_id' => $menuId,
            'menu_category_id' => $category->id,
            'name' => $data['name'],
            'slug' => $slug,
            'short_description' => $data['short_description'] ?? null,
            'description' => $data['description'] ?? null,
            'base_price_cents' => $data['base_price_cents'],
            'compare_at_price_cents' => $data['compare_at_price_cents'] ?? null,
            'cost_price_cents' => $data['cost_price_cents'] ?? null,
            'preparation_minutes' => $data['preparation_minutes'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'is_available' => $data['is_available'] ?? true,
            'is_featured' => $data['is_featured'] ?? false,
            'is_vegetarian' => $data['is_vegetarian'] ?? false,
            'is_vegan' => $data['is_vegan'] ?? false,
            'is_gluten_free' => $data['is_gluten_free'] ?? false,
            'is_halal' => $data['is_halal'] ?? false,
            'spice_level' => $data['spice_level'] ?? 'none',
            'sort_order' => (int) MenuItem::query()->where('restaurant_id', $restaurantId)->max('sort_order') + 1,
        ]);
        $this->audit($request, 'menu.item_created', $item, $restaurantId);

        return ApiResponse::success(['item' => $this->itemPayload($item->load(['variants', 'allergens']), true)], status: 201);
    }

    public function uploadItemImage(Request $request, string $publicId)
    {
        $restaurantId = RestaurantContext::id($request);
        $item = MenuItem::query()->where('restaurant_id', $restaurantId)->where('public_id', $publicId)->firstOrFail();
        $request->validate(['file' => ['required', 'file']]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('file');
        $processed = app(\App\Services\Media\PublicImageService::class)->storeMenuItemImage($file, $item->public_id);

        $item->update([
            'image_path' => $processed['original'],
            'image_urls' => [
                'original' => $processed['original'],
                'thumbnail' => $processed['thumbnail'],
                'card' => $processed['card'],
                'large' => $processed['large'],
            ],
        ]);
        $this->audit($request, 'menu.item_image_changed', $item, $restaurantId);

        return ApiResponse::success([
            'item' => $this->itemPayload($item->fresh()->load(['variants', 'allergens']), true),
            'image' => app(\App\Services\Media\PublicImageService::class)->toPublicPayload($item->fresh()->image_urls, 'item'),
        ]);
    }

    public function showItem(Request $request, string $publicId)
    {
        $restaurantId = RestaurantContext::id($request);
        $item = MenuItem::query()
            ->where('restaurant_id', $restaurantId)
            ->where('public_id', $publicId)
            ->with(['variants', 'modifierGroups.options', 'allergens'])
            ->firstOrFail();

        return ApiResponse::success(['item' => $this->itemPayload($item, true)]);
    }

    public function updateItem(Request $request, string $publicId)
    {
        $restaurantId = RestaurantContext::id($request);
        $item = MenuItem::query()->where('restaurant_id', $restaurantId)->where('public_id', $publicId)->firstOrFail();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'base_price_cents' => ['sometimes', 'integer', 'min:0'],
            'compare_at_price_cents' => ['nullable', 'integer', 'min:0'],
            'cost_price_cents' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_vegetarian' => ['sometimes', 'boolean'],
            'is_vegan' => ['sometimes', 'boolean'],
            'is_gluten_free' => ['sometimes', 'boolean'],
            'is_halal' => ['sometimes', 'boolean'],
            'spice_level' => ['nullable', Rule::in(config('restaurant.spice_levels'))],
        ]);
        $base = $data['base_price_cents'] ?? $item->base_price_cents;
        $compare = $data['compare_at_price_cents'] ?? $item->compare_at_price_cents;
        if ($compare !== null && $compare <= $base) {
            throw ValidationException::withMessages(['compare_at_price_cents' => ['Compare-at price must exceed base price.']]);
        }
        $item->update($data);
        $this->audit($request, 'menu.item_updated', $item, $restaurantId);

        return ApiResponse::success(['item' => $this->itemPayload($item->fresh()->load(['variants', 'allergens']), true)]);
    }

    public function deleteItem(Request $request, string $publicId)
    {
        $restaurantId = RestaurantContext::id($request);
        $item = MenuItem::query()->where('restaurant_id', $restaurantId)->where('public_id', $publicId)->firstOrFail();
        $item->delete();
        $this->audit($request, 'menu.item_archived', $item, $restaurantId);

        return ApiResponse::success(message: 'Archived.');
    }

    public function duplicateItem(Request $request, string $publicId)
    {
        $restaurantId = RestaurantContext::id($request);
        $source = MenuItem::query()->where('restaurant_id', $restaurantId)->where('public_id', $publicId)->firstOrFail();
        $copy = $source->replicate(['public_id', 'slug']);
        $copy->public_id = (string) Str::uuid();
        $copy->slug = $source->slug.'-copy-'.Str::random(4);
        $copy->name = $source->name.' (Copy)';
        $copy->save();
        $this->audit($request, 'menu.item_duplicated', $copy, $restaurantId);

        return ApiResponse::success(['item' => $this->itemPayload($copy, true)], status: 201);
    }

    public function updateAvailability(Request $request, string $publicId)
    {
        $restaurantId = RestaurantContext::id($request);
        $item = MenuItem::query()->where('restaurant_id', $restaurantId)->where('public_id', $publicId)->firstOrFail();
        $data = $request->validate([
            'action' => ['required', Rule::in(['sold_out', 'available', 'available_tomorrow'])],
        ]);
        if ($data['action'] === 'sold_out') {
            $item->update(['is_available' => false]);
        } elseif ($data['action'] === 'available') {
            $item->update(['is_available' => true]);
        } else {
            $item->update([
                'is_available' => false,
                'available_from' => now()->addDay()->startOfDay(),
            ]);
        }
        $this->audit($request, 'menu.item_availability_changed', $item, $restaurantId);

        return ApiResponse::success(['item' => $this->itemPayload($item->fresh(), true)]);
    }

    public function bulkItems(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $data = $request->validate([
            'item_public_ids' => ['required', 'array', 'min:1'],
            'item_public_ids.*' => ['uuid'],
            'action' => ['required', Rule::in([
                'sold_out', 'available', 'activate', 'deactivate', 'archive',
                'move_category', 'available_tomorrow', 'available_at',
            ])],
            'menu_category_public_id' => ['nullable', 'uuid'],
            'available_at' => ['nullable', 'date'],
        ]);

        $items = MenuItem::query()
            ->where('restaurant_id', $restaurantId)
            ->whereIn('public_id', $data['item_public_ids'])
            ->get();

        if ($items->count() !== count($data['item_public_ids'])) {
            throw ValidationException::withMessages(['item_public_ids' => ['Invalid item selection.']]);
        }

        $categoryId = null;
        if ($data['action'] === 'move_category') {
            $categoryId = MenuCategory::query()
                ->where('restaurant_id', $restaurantId)
                ->where('public_id', $data['menu_category_public_id'] ?? '')
                ->value('id');
            if (! $categoryId) {
                throw ValidationException::withMessages(['menu_category_public_id' => ['Category not found.']]);
            }
        }

        foreach ($items as $item) {
            match ($data['action']) {
                'sold_out' => $item->update(['is_available' => false]),
                'available' => $item->update(['is_available' => true, 'available_from' => null]),
                'activate' => $item->update(['is_active' => true]),
                'deactivate' => $item->update(['is_active' => false]),
                'archive' => $item->delete(),
                'move_category' => $item->update(['menu_category_id' => $categoryId]),
                'available_tomorrow' => $item->update([
                    'is_available' => false,
                    'available_from' => now()->addDay()->startOfDay(),
                ]),
                'available_at' => $item->update([
                    'is_available' => false,
                    'available_from' => $data['available_at'] ?? now(),
                ]),
            };
        }

        $this->audit($request, 'menu.bulk_action', $items->first(), $restaurantId);

        return ApiResponse::success(['updated' => $items->count()]);
    }

    public function reorderItems(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['uuid'],
        ]);
        foreach ($data['order'] as $index => $publicId) {
            MenuItem::query()
                ->where('restaurant_id', $restaurantId)
                ->where('public_id', $publicId)
                ->update(['sort_order' => $index]);
        }

        return ApiResponse::success(message: 'Reordered.');
    }

    public function syncAllergens(Request $request, string $publicId)
    {
        $restaurantId = RestaurantContext::id($request);
        $item = MenuItem::query()->where('restaurant_id', $restaurantId)->where('public_id', $publicId)->firstOrFail();
        $data = $request->validate([
            'allergens' => ['required', 'array'],
            'allergens.*.allergen_id' => ['required', 'integer', 'exists:allergens,id'],
            'allergens.*.presence_type' => ['required', Rule::in(['contains', 'may_contain', 'prepared_near'])],
        ]);
        $sync = [];
        foreach ($data['allergens'] as $row) {
            $sync[$row['allergen_id']] = ['presence_type' => $row['presence_type']];
        }
        $item->allergens()->sync($sync);
        $this->audit($request, 'menu.allergens_changed', $item, $restaurantId);

        return ApiResponse::success(['item' => $this->itemPayload($item->fresh()->load('allergens'), true)]);
    }

    public function listModifierGroups(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $groups = ModifierGroup::query()->where('restaurant_id', $restaurantId)->with('options')->orderBy('sort_order')->get();

        return ApiResponse::success(['modifier_groups' => $groups]);
    }

    public function storeModifierGroup(Request $request)
    {
        $restaurantId = RestaurantContext::id($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'selection_type' => ['required', Rule::in(['single', 'multiple'])],
            'minimum_selections' => ['required', 'integer', 'min:0'],
            'maximum_selections' => ['required', 'integer', 'min:1'],
            'is_required' => ['sometimes', 'boolean'],
        ]);
        if ($data['minimum_selections'] > $data['maximum_selections']) {
            throw ValidationException::withMessages(['minimum_selections' => ['Minimum cannot exceed maximum.']]);
        }
        if ($data['selection_type'] === 'single' && $data['maximum_selections'] > 1) {
            throw ValidationException::withMessages(['maximum_selections' => ['Single selection allows at most one choice.']]);
        }
        $group = ModifierGroup::query()->create(array_merge($data, [
            'restaurant_id' => $restaurantId,
            'public_id' => (string) Str::uuid(),
        ]));
        $this->audit($request, 'menu.modifier_changed', $group, $restaurantId);

        return ApiResponse::success(['modifier_group' => $group], status: 201);
    }

    public function storeModifierOption(Request $request, string $groupPublicId)
    {
        $restaurantId = RestaurantContext::id($request);
        $group = ModifierGroup::query()->where('restaurant_id', $restaurantId)->where('public_id', $groupPublicId)->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price_adjustment_cents' => ['required', 'integer'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
        $option = ModifierOption::query()->create(array_merge($data, [
            'restaurant_id' => $restaurantId,
            'modifier_group_id' => $group->id,
            'public_id' => (string) Str::uuid(),
        ]));
        $this->audit($request, 'menu.modifier_changed', $option, $restaurantId);

        return ApiResponse::success(['modifier_option' => $option], status: 201);
    }

    public function syncItemModifiers(Request $request, string $publicId)
    {
        $restaurantId = RestaurantContext::id($request);
        $item = MenuItem::query()->where('restaurant_id', $restaurantId)->where('public_id', $publicId)->firstOrFail();
        $data = $request->validate([
            'modifier_group_public_ids' => ['required', 'array'],
            'modifier_group_public_ids.*' => ['uuid'],
        ]);
        $groupIds = ModifierGroup::query()
            ->where('restaurant_id', $restaurantId)
            ->whereIn('public_id', $data['modifier_group_public_ids'])
            ->pluck('id');
        if ($groupIds->count() !== count($data['modifier_group_public_ids'])) {
            throw ValidationException::withMessages(['modifier_group_public_ids' => ['Invalid modifier group for this restaurant.']]);
        }
        $sync = [];
        foreach ($groupIds as $index => $id) {
            $sync[$id] = ['sort_order' => $index];
        }
        $item->modifierGroups()->sync($sync);
        $this->audit($request, 'menu.modifier_changed', $item, $restaurantId);

        return ApiResponse::success(['item' => $this->itemPayload($item->fresh()->load('modifierGroups'), true)]);
    }

    public function syncVariants(Request $request, string $publicId)
    {
        $restaurantId = RestaurantContext::id($request);
        $item = MenuItem::query()->where('restaurant_id', $restaurantId)->where('public_id', $publicId)->firstOrFail();
        $data = $request->validate([
            'variants' => ['required', 'array'],
            'variants.*.name' => ['required', 'string', 'max:255'],
            'variants.*.price_cents' => ['required', 'integer', 'min:0'],
            'variants.*.is_default' => ['sometimes', 'boolean'],
            'variants.*.sku' => ['nullable', 'string', 'max:64'],
        ]);
        $defaults = collect($data['variants'])->where('is_default', true)->count();
        if ($defaults > 1) {
            throw ValidationException::withMessages(['variants' => ['Only one default variant is allowed.']]);
        }
        DB::transaction(function () use ($item, $data, $restaurantId, $request) {
            MenuItemVariant::query()->where('menu_item_id', $item->id)->delete();
            $hasDefault = false;
            foreach ($data['variants'] as $index => $row) {
                $isDefault = ! empty($row['is_default']) && ! $hasDefault;
                if ($isDefault) {
                    $hasDefault = true;
                }
                MenuItemVariant::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'restaurant_id' => $restaurantId,
                    'menu_item_id' => $item->id,
                    'name' => $row['name'],
                    'sku' => $row['sku'] ?? null,
                    'price_cents' => $row['price_cents'],
                    'is_default' => $isDefault,
                    'sort_order' => $index,
                ]);
            }
        });
        $this->audit($request, 'menu.variant_changed', $item, $restaurantId);

        return ApiResponse::success(['item' => $this->itemPayload($item->fresh()->load('variants'), true)]);
    }

    private function uniqueItemSlug(int $restaurantId, string $base): string
    {
        $slug = $base ?: 'item';
        $candidate = $slug;
        $i = 2;
        while (MenuItem::query()->where('restaurant_id', $restaurantId)->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$i;
            $i++;
        }

        return $candidate;
    }

    private function itemPayload(MenuItem $item, bool $includePrivate): array
    {
        $payload = [
            'public_id' => $item->public_id,
            'name' => $item->name,
            'slug' => $item->slug,
            'short_description' => $item->short_description,
            'description' => $item->description,
            'base_price_cents' => $item->base_price_cents,
            'compare_at_price_cents' => $item->compare_at_price_cents,
            'is_active' => $item->is_active,
            'is_available' => $item->is_available,
            'is_featured' => $item->is_featured,
            'dietary' => [
                'is_vegetarian' => $item->is_vegetarian,
                'is_vegan' => $item->is_vegan,
                'is_gluten_free' => $item->is_gluten_free,
                'is_halal' => $item->is_halal,
            ],
            'spice_level' => $item->spice_level,
            'sort_order' => $item->sort_order,
            'image_path' => $item->image_path,
            'image' => app(\App\Services\Media\PublicImageService::class)->toPublicPayload($item->image_urls, 'item'),
            'variants' => $item->relationLoaded('variants') ? $item->variants : [],
            'allergens' => $item->relationLoaded('allergens')
                ? $item->allergens->map(fn (Allergen $a) => [
                    'slug' => $a->slug,
                    'name' => $a->name,
                    'presence_type' => $a->pivot->presence_type,
                ])
                : [],
        ];
        if ($includePrivate) {
            $payload['cost_price_cents'] = $item->cost_price_cents;
        }

        return $payload;
    }

    private function audit(Request $request, string $action, $model, int $restaurantId): void
    {
        /** @var User $user */
        $user = $request->user();
        $this->auditLogger->log($action, $user, $model instanceof MenuItem || $model instanceof MenuCategory || $model instanceof Menu ? $model : null, restaurantId: $restaurantId, request: $request);
    }
}
