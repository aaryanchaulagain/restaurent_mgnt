<?php

namespace Tests\Feature\Menu;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MenuManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_owner_creates_category(): void
    {
        [$user] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/restaurant/menu-categories', [
            'name' => 'Starters',
        ])->assertStatus(201)
            ->assertJsonPath('data.category.name', 'Starters');
    }

    public function test_owner_creates_item(): void
    {
        [$user, $restaurant] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $category = MenuCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $this->defaultMenu($restaurant)->id,
            'name' => 'Mains',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/restaurant/menu-items', [
            'menu_category_public_id' => $category->public_id,
            'name' => 'Burger',
            'base_price_cents' => 1500,
        ])->assertStatus(201)
            ->assertJsonPath('data.item.name', 'Burger');
    }

    public function test_category_ownership_enforced_cross_restaurant(): void
    {
        [$userA, $restaurantA] = $this->restaurantOwner();
        [$userB, $restaurantB] = $this->restaurantOwner();

        $catB = MenuCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurantB->id,
            'menu_id' => $this->defaultMenu($restaurantB)->id,
            'name' => 'Other Cat',
            'is_active' => true,
        ]);

        Sanctum::actingAs($userA);
        $this->postJson('/api/v1/restaurant/menu-items', [
            'menu_category_public_id' => $catB->public_id,
            'name' => 'Sneaky Item',
            'base_price_cents' => 1000,
        ])->assertStatus(404);
    }

    public function test_duplicate_item_works(): void
    {
        [$user, $restaurant] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $menu = $this->defaultMenu($restaurant);
        $cat = MenuCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'name' => 'Mains',
            'is_active' => true,
        ]);
        $item = MenuItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'menu_category_id' => $cat->id,
            'name' => 'Original',
            'slug' => 'original-' . Str::random(4),
            'base_price_cents' => 1200,
            'is_active' => true,
            'is_available' => true,
        ]);

        $this->postJson("/api/v1/restaurant/menu-items/{$item->public_id}/duplicate")
            ->assertStatus(201);
    }

    public function test_bulk_sold_out_works(): void
    {
        [$user, $restaurant] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $menu = $this->defaultMenu($restaurant);
        $cat = MenuCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'name' => 'Mains',
            'is_active' => true,
        ]);
        $item = MenuItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'menu_category_id' => $cat->id,
            'name' => 'Bulk Item',
            'slug' => 'bulk-' . Str::random(4),
            'base_price_cents' => 900,
            'is_active' => true,
            'is_available' => true,
        ]);

        $this->postJson('/api/v1/restaurant/menu-items/bulk', [
            'item_public_ids' => [$item->public_id],
            'action' => 'sold_out',
        ])->assertOk();

        $item->refresh();
        $this->assertFalse($item->is_available);
    }

    public function test_only_one_default_variant_allowed(): void
    {
        [$user, $restaurant] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $menu = $this->defaultMenu($restaurant);
        $cat = MenuCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'name' => 'Mains',
            'is_active' => true,
        ]);
        $item = MenuItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'menu_category_id' => $cat->id,
            'name' => 'Variant Item',
            'slug' => 'variant-' . Str::random(4),
            'base_price_cents' => 1000,
            'is_active' => true,
            'is_available' => true,
        ]);

        $this->putJson("/api/v1/restaurant/menu-items/{$item->public_id}/variants", [
            'variants' => [
                ['name' => 'Small', 'price_cents' => 800, 'is_default' => true],
                ['name' => 'Large', 'price_cents' => 1200, 'is_default' => true],
            ],
        ])->assertStatus(422);
    }

    public function test_cross_restaurant_modifier_assignment_fails(): void
    {
        [$userA, $restaurantA] = $this->restaurantOwner();
        [$userB, $restaurantB] = $this->restaurantOwner();

        $groupB = ModifierGroup::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurantB->id,
            'name' => 'Sauces',
            'selection_type' => 'single',
            'minimum_selections' => 0,
            'maximum_selections' => 1,
        ]);

        Sanctum::actingAs($userA);

        $menu = $this->defaultMenu($restaurantA);
        $cat = MenuCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurantA->id,
            'menu_id' => $menu->id,
            'name' => 'Mains',
            'is_active' => true,
        ]);
        $item = MenuItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurantA->id,
            'menu_id' => $menu->id,
            'menu_category_id' => $cat->id,
            'name' => 'Test',
            'slug' => 'mod-test-' . Str::random(4),
            'base_price_cents' => 1000,
            'is_active' => true,
            'is_available' => true,
        ]);

        $this->putJson("/api/v1/restaurant/menu-items/{$item->public_id}/modifier-groups", [
            'modifier_group_public_ids' => [$groupB->public_id],
        ])->assertStatus(422);
    }

    public function test_cost_price_in_admin_response(): void
    {
        [$user, $restaurant] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $menu = $this->defaultMenu($restaurant);
        $cat = MenuCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'name' => 'Mains',
            'is_active' => true,
        ]);
        $item = MenuItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'menu_category_id' => $cat->id,
            'name' => 'Cost Item',
            'slug' => 'cost-' . Str::random(4),
            'base_price_cents' => 1500,
            'cost_price_cents' => 600,
            'is_active' => true,
            'is_available' => true,
        ]);

        $response = $this->getJson("/api/v1/restaurant/menu-items/{$item->public_id}")->assertOk();
        $this->assertArrayHasKey('cost_price_cents', $response->json('data.item'));
        $this->assertEquals(600, $response->json('data.item.cost_price_cents'));
    }

    public function test_reorder_categories_works(): void
    {
        [$user, $restaurant] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $menu = $this->defaultMenu($restaurant);
        $cat1 = MenuCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'name' => 'First',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $cat2 = MenuCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'name' => 'Second',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->postJson('/api/v1/restaurant/menu-categories/reorder', [
            'order' => [$cat2->public_id, $cat1->public_id],
        ])->assertOk();

        $cat1->refresh();
        $cat2->refresh();
        $this->assertEquals(1, $cat1->sort_order);
        $this->assertEquals(0, $cat2->sort_order);
    }

    private function seedPermissions(): void
    {
        foreach (config('suvakamana.permissions') as $slug) {
            Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }
        $ownerRole = Role::query()->firstOrCreate(['slug' => 'restaurant_owner'], ['name' => 'Owner', 'guard' => 'web']);
        $ownerSlugs = [
            'view_restaurant_dashboard', 'view_restaurant_profile', 'manage_restaurant_profile',
            'manage_restaurant_media', 'manage_restaurant_hours', 'manage_restaurant_service_areas',
            'activate_restaurant', 'temporarily_close_restaurant',
            'view_menu', 'manage_menu_categories', 'manage_menu_items', 'manage_menu_variants',
            'manage_menu_modifiers', 'manage_menu_allergens', 'manage_menu_availability',
        ];
        $ownerRole->permissions()->sync(Permission::query()->whereIn('slug', $ownerSlugs)->pluck('id'));
    }

    /** @return array{0: User, 1: Restaurant} */
    private function restaurantOwner(): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'restaurant_owner')->firstOrFail();
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'menu-test-' . Str::random(4),
            'legal_business_name' => 'Menu Test Pty Ltd',
            'trading_name' => 'Menu Test Restaurant',
            'status' => RestaurantStatus::Active,
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
        ]);
        RestaurantUser::query()->create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $user->roles()->attach($role->id, ['restaurant_id' => $restaurant->id]);
        $user->load('roles.permissions');

        return [$user, $restaurant];
    }

    private function defaultMenu(Restaurant $restaurant): Menu
    {
        return Menu::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'is_default' => true],
            [
                'public_id' => (string) Str::uuid(),
                'name' => 'Main',
                'status' => 'active',
                'is_default' => true,
            ]
        );
    }
}
