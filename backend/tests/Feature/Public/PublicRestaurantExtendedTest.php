<?php

namespace Tests\Feature\Public;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicRestaurantExtendedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (config('suvakamana.permissions') as $slug) {
            Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }
        Role::query()->firstOrCreate(['slug' => 'customer'], ['name' => 'Customer', 'guard' => 'web']);
    }

    public function test_active_published_restaurant_returned_by_slug(): void
    {
        $restaurant = $this->createRestaurant('active-pub', RestaurantStatus::Active, published: true);

        $this->getJson("/api/v1/public/restaurants/{$restaurant->slug}")
            ->assertOk()
            ->assertJsonPath('data.restaurant.slug', $restaurant->slug);
    }

    public function test_pending_restaurant_returns_404(): void
    {
        $this->createRestaurant('pending-one', RestaurantStatus::PendingSetup, published: false);

        $this->getJson('/api/v1/public/restaurants/pending-one')->assertStatus(404);
    }

    public function test_suspended_restaurant_returns_404(): void
    {
        $restaurant = $this->createRestaurant('suspended-one', RestaurantStatus::Active, published: true);
        $restaurant->update(['suspended_at' => now()]);

        $this->getJson('/api/v1/public/restaurants/suspended-one')->assertStatus(404);
    }

    public function test_unknown_slug_returns_404(): void
    {
        $this->getJson('/api/v1/public/restaurants/does-not-exist')->assertStatus(404);
    }

    public function test_public_response_excludes_cost_price(): void
    {
        $restaurant = $this->createRestaurant('cost-hidden', RestaurantStatus::Active, published: true);
        $this->createMenuWithItem($restaurant, costPriceCents: 500);

        $items = $this->getJson("/api/v1/public/restaurants/{$restaurant->slug}/menu")
            ->assertOk()
            ->json('data.items');

        $this->assertNotEmpty($items);
        $this->assertArrayNotHasKey('cost_price_cents', $items[0]);
    }

    public function test_public_response_excludes_commission_fields(): void
    {
        $restaurant = $this->createRestaurant('comm-hidden', RestaurantStatus::Active, published: true);

        $response = $this->getJson("/api/v1/public/restaurants/{$restaurant->slug}")
            ->assertOk()
            ->json('data.restaurant');

        $this->assertArrayNotHasKey('commission_rate', $response);
        $this->assertArrayNotHasKey('commission_type', $response);
    }

    public function test_inactive_items_hidden_from_menu(): void
    {
        $restaurant = $this->createRestaurant('inactive-items', RestaurantStatus::Active, published: true);
        $this->createMenuWithItem($restaurant, isActive: false);

        $items = $this->getJson("/api/v1/public/restaurants/{$restaurant->slug}/menu")
            ->assertOk()
            ->json('data.items');

        $this->assertEmpty($items);
    }

    public function test_sold_out_items_visible_but_marked_unavailable(): void
    {
        $restaurant = $this->createRestaurant('soldout-items', RestaurantStatus::Active, published: true);
        $this->createMenuWithItem($restaurant, isAvailable: false);

        $items = $this->getJson("/api/v1/public/restaurants/{$restaurant->slug}/menu")
            ->assertOk()
            ->json('data.items');

        $this->assertNotEmpty($items);
        $this->assertFalse($items[0]['is_available']);
    }

    public function test_inactive_categories_hidden(): void
    {
        $restaurant = $this->createRestaurant('inactive-cats', RestaurantStatus::Active, published: true);
        $menu = Menu::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Main',
            'status' => 'active',
            'is_default' => true,
        ]);
        MenuCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'name' => 'Hidden Category',
            'is_active' => false,
        ]);

        $categories = $this->getJson("/api/v1/public/restaurants/{$restaurant->slug}/menu")
            ->assertOk()
            ->json('data.categories');

        $this->assertEmpty($categories);
    }

    public function test_empty_menu_returns_valid_response(): void
    {
        $restaurant = $this->createRestaurant('empty-menu', RestaurantStatus::Active, published: true);

        $this->getJson("/api/v1/public/restaurants/{$restaurant->slug}/menu")
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'categories']]);
    }

    private function createRestaurant(string $slug, RestaurantStatus $status, bool $published): Restaurant
    {
        return Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => $slug,
            'legal_business_name' => 'Test Pty Ltd',
            'trading_name' => ucfirst($slug),
            'status' => $status,
            'published_at' => $published ? now() : null,
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
            'accepting_orders' => true,
            'pickup_enabled' => true,
        ]);
    }

    private function createMenuWithItem(
        Restaurant $restaurant,
        int $costPriceCents = null,
        bool $isActive = true,
        bool $isAvailable = true,
    ): MenuItem {
        $menu = Menu::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Main',
            'status' => 'active',
            'is_default' => true,
        ]);
        $cat = MenuCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'name' => 'Mains',
            'is_active' => true,
        ]);

        return MenuItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'menu_category_id' => $cat->id,
            'name' => 'Test Item',
            'slug' => 'item-' . $restaurant->slug,
            'base_price_cents' => 1250,
            'cost_price_cents' => $costPriceCents,
            'is_active' => $isActive,
            'is_available' => $isAvailable,
        ]);
    }
}
