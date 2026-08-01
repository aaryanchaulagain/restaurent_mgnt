<?php

namespace Tests\Feature\Inventory;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Business;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemInventory;
use App\Models\MenuItemVariant;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StableVariantsAndInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_variant_sync_preserves_public_id(): void
    {
        [$user, $restaurant] = $this->groceryOwner();
        Sanctum::actingAs($user);

        $item = $this->makeItem($restaurant, 'Milk');
        $create = $this->putJson("/api/v1/restaurant/menu-items/{$item->public_id}/variants", [
            'variants' => [
                ['name' => '1L', 'price_cents' => 450, 'is_default' => true],
            ],
        ])->assertOk();

        $variantId = $create->json('data.item.variants.0.public_id');
        $this->assertNotEmpty($variantId);

        $this->putJson("/api/v1/restaurant/menu-items/{$item->public_id}/variants", [
            'variants' => [
                ['public_id' => $variantId, 'name' => '1 Litre', 'price_cents' => 499, 'is_default' => true],
                ['name' => '2L', 'price_cents' => 799, 'is_default' => false],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('menu_item_variants', [
            'public_id' => $variantId,
            'name' => '1 Litre',
            'price_cents' => 499,
        ]);
        $this->assertSame(2, MenuItemVariant::query()->where('menu_item_id', $item->id)->count());
    }

    public function test_atomic_stock_adjustment_and_availability_sync(): void
    {
        [$user, $restaurant] = $this->groceryOwner();
        Sanctum::actingAs($user);
        $item = $this->makeItem($restaurant, 'Rice');

        $this->putJson("/api/v1/restaurant/inventory/items/{$item->public_id}", [
            'track_stock' => true,
            'quantity_on_hand' => 10,
            'low_stock_threshold' => 3,
        ])->assertOk();

        $this->postJson("/api/v1/restaurant/inventory/items/{$item->public_id}/adjust", [
            'delta' => -10,
            'reason' => 'Sold out day',
        ])->assertOk()
            ->assertJsonPath('data.inventory.quantity_on_hand', 0)
            ->assertJsonPath('data.inventory.is_in_stock', false);

        $item->refresh();
        $this->assertFalse($item->is_available);

        $this->postJson("/api/v1/restaurant/inventory/items/{$item->public_id}/adjust", [
            'set_quantity' => 5,
            'reason' => 'Restock',
        ])->assertOk()
            ->assertJsonPath('data.inventory.quantity_on_hand', 5);

        $item->refresh();
        $this->assertTrue($item->is_available);

        $this->getJson('/api/v1/restaurant/inventory/low-stock')
            ->assertOk()
            ->assertJsonPath('data.inventories.0.menu_item_public_id', $item->public_id);
    }

    public function test_restaurant_rejects_counted_inventory(): void
    {
        [$user, $restaurant] = $this->restaurantOwner();
        Sanctum::actingAs($user);
        $item = $this->makeItem($restaurant, 'Curry');

        $this->putJson("/api/v1/restaurant/inventory/items/{$item->public_id}", [
            'quantity_on_hand' => 5,
        ])->assertStatus(422);
    }

    public function test_public_menu_hides_out_of_stock_without_exposing_qty(): void
    {
        [, $restaurant] = $this->groceryOwner();
        $item = $this->makeItem($restaurant, 'Flour');
        $item->update(['is_available' => true]);

        MenuItemInventory::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_item_id' => $item->id,
            'menu_item_variant_id' => null,
            'variant_scope' => 0,
            'track_stock' => true,
            'quantity_on_hand' => 0,
            'low_stock_threshold' => 2,
            'force_unavailable' => false,
        ]);

        $response = $this->getJson("/api/v1/public/restaurants/{$restaurant->slug}/menu")->assertOk();
        $payload = collect($response->json('data.items'))->firstWhere('public_id', $item->public_id);
        $this->assertNotNull($payload);
        $this->assertFalse($payload['is_available']);
        $this->assertFalse($payload['in_stock']);
        $this->assertArrayNotHasKey('quantity_on_hand', $payload);
    }

    private function seedPermissions(): void
    {
        foreach (config('suvakamana.permissions') as $slug) {
            Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }
        $ownerRole = Role::query()->firstOrCreate(['slug' => 'restaurant_owner'], ['name' => 'Owner', 'guard' => 'web']);
        $ownerSlugs = [
            'view_restaurant_dashboard', 'view_restaurant_profile', 'manage_restaurant_profile',
            'view_menu', 'manage_menu_categories', 'manage_menu_items', 'manage_menu_variants',
            'manage_menu_modifiers', 'manage_menu_allergens', 'manage_menu_availability',
            'view_inventory', 'manage_inventory',
        ];
        $ownerRole->permissions()->sync(Permission::query()->whereIn('slug', $ownerSlugs)->pluck('id'));
    }

    /** @return array{0: User, 1: Restaurant} */
    private function groceryOwner(): array
    {
        return $this->ownerWithType('grocery');
    }

    /** @return array{0: User, 1: Restaurant} */
    private function restaurantOwner(): array
    {
        return $this->ownerWithType('restaurant');
    }

    /** @return array{0: User, 1: Restaurant} */
    private function ownerWithType(string $businessType): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'restaurant_owner')->firstOrFail();
        $business = Business::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => 'Inventory Biz',
            'slug' => 'inv-biz-'.Str::random(6),
            'business_type' => $businessType,
            'status' => 'active',
        ]);
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'inv-test-'.Str::random(4),
            'legal_business_name' => 'Inventory Test Pty Ltd',
            'trading_name' => 'Inventory Test',
            'status' => RestaurantStatus::Active,
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
            'business_id' => $business->id,
            'published_at' => now(),
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

    private function makeItem(Restaurant $restaurant, string $name): MenuItem
    {
        $menu = Menu::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'is_default' => true],
            [
                'public_id' => (string) Str::uuid(),
                'name' => 'Main',
                'status' => 'active',
                'is_default' => true,
            ]
        );
        $cat = MenuCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'name' => 'General',
            'is_active' => true,
        ]);

        return MenuItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'menu_category_id' => $cat->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'base_price_cents' => 1000,
            'is_active' => true,
            'is_available' => true,
        ]);
    }
}
