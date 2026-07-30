<?php

namespace Tests\Feature\Public;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Restaurant;
use App\Models\RestaurantOpeningHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuvakamanaRestaurantTest extends TestCase
{
    use RefreshDatabase;

    private function seedSuvakamana(): Restaurant
    {
        $restaurant = Restaurant::query()->firstOrCreate(['slug' => 'suvakamana-restaurant'], [
            'public_id' => (string) Str::uuid(),
            'legal_business_name' => 'Suvakamana Restaurant',
            'trading_name' => 'Suvakamana Restaurant',
            'ownership_type' => 'first_party',
            'status' => RestaurantStatus::Active,
            'accepting_orders' => true,
            'published_at' => now(),
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
            'pickup_enabled' => true,
            'restaurant_delivery_enabled' => true,
        ]);
        for ($d = 0; $d < 7; $d++) {
            RestaurantOpeningHour::query()->firstOrCreate(
                ['restaurant_id' => $restaurant->id, 'day_of_week' => $d, 'service_type' => 'all', 'is_closed' => false],
                ['opens_at' => '00:00', 'closes_at' => '23:59']
            );
        }
        $menu = Menu::query()->firstOrCreate(['restaurant_id' => $restaurant->id, 'is_default' => true], [
            'public_id' => (string) Str::uuid(), 'name' => 'Main', 'status' => 'active',
        ]);
        $cat = MenuCategory::query()->firstOrCreate(['restaurant_id' => $restaurant->id, 'name' => 'Mains'], [
            'public_id' => (string) Str::uuid(), 'menu_id' => $menu->id, 'is_active' => true,
        ]);
        MenuItem::query()->firstOrCreate(['restaurant_id' => $restaurant->id, 'slug' => 'dal-bhat'], [
            'public_id' => (string) Str::uuid(), 'menu_id' => $menu->id, 'menu_category_id' => $cat->id,
            'name' => 'Dal Bhat', 'base_price_cents' => 1690, 'is_active' => true, 'is_available' => true,
            'is_featured' => true, 'cost_price_cents' => 500,
        ]);
        MenuItem::query()->firstOrCreate(['restaurant_id' => $restaurant->id, 'slug' => 'samosa'], [
            'public_id' => (string) Str::uuid(), 'menu_id' => $menu->id, 'menu_category_id' => $cat->id,
            'name' => 'Samosa', 'base_price_cents' => 890, 'is_active' => true, 'is_available' => false,
            'cost_price_cents' => 300,
        ]);
        MenuItem::query()->firstOrCreate(['restaurant_id' => $restaurant->id, 'slug' => 'hidden-item'], [
            'public_id' => (string) Str::uuid(), 'menu_id' => $menu->id, 'menu_category_id' => $cat->id,
            'name' => 'Hidden', 'base_price_cents' => 100, 'is_active' => false, 'is_available' => true,
            'cost_price_cents' => 50,
        ]);

        return $restaurant;
    }

    public function test_platform_restaurant_endpoint_returns_suvakamana(): void
    {
        $this->seedSuvakamana();
        $this->getJson('/api/v1/public/platform-restaurant')
            ->assertOk()
            ->assertJsonPath('data.restaurant.slug', 'suvakamana-restaurant')
            ->assertJsonPath('data.restaurant.is_platform_restaurant', true);
    }

    public function test_featured_items_returned(): void
    {
        $this->seedSuvakamana();
        $response = $this->getJson('/api/v1/public/platform-restaurant')->assertOk();
        $items = $response->json('data.featured_items');
        $this->assertNotEmpty($items);
        $names = collect($items)->pluck('name')->all();
        $this->assertContains('Dal Bhat', $names);
    }

    public function test_inactive_item_hidden_from_platform_endpoint(): void
    {
        $this->seedSuvakamana();
        $response = $this->getJson('/api/v1/public/platform-restaurant')->assertOk();
        $names = collect($response->json('data.featured_items'))->pluck('name')->all();
        $this->assertNotContains('Hidden', $names);
    }

    public function test_sold_out_item_visible_as_unavailable(): void
    {
        $this->seedSuvakamana();
        $response = $this->getJson('/api/v1/public/platform-restaurant')->assertOk();
        $samosa = collect($response->json('data.featured_items'))->firstWhere('name', 'Samosa');
        $this->assertNotNull($samosa);
        $this->assertFalse($samosa['is_available']);
    }

    public function test_cost_price_hidden_from_public(): void
    {
        $this->seedSuvakamana();
        $response = $this->getJson('/api/v1/public/platform-restaurant')->assertOk();
        $item = $response->json('data.featured_items.0');
        $this->assertArrayNotHasKey('cost_price_cents', $item);
    }

    public function test_suvakamana_visible_in_public_listing(): void
    {
        $this->seedSuvakamana();
        $response = $this->getJson('/api/v1/public/restaurants')->assertOk();
        $slugs = collect($response->json('data.restaurants'))->pluck('slug')->all();
        $this->assertContains('suvakamana-restaurant', $slugs);
    }

    public function test_suvakamana_visible_when_no_other_restaurants(): void
    {
        $this->seedSuvakamana();
        $response = $this->getJson('/api/v1/public/restaurants')->assertOk();
        $this->assertCount(1, $response->json('data.restaurants'));
    }

    public function test_suvakamana_slug_endpoint_works(): void
    {
        $this->seedSuvakamana();
        $this->getJson('/api/v1/public/restaurants/suvakamana-restaurant')
            ->assertOk()
            ->assertJsonPath('data.restaurant.is_platform_restaurant', true);
    }

    public function test_suvakamana_menu_endpoint_returns_items(): void
    {
        $this->seedSuvakamana();
        $response = $this->getJson('/api/v1/public/restaurants/suvakamana-restaurant/menu')->assertOk();
        $items = $response->json('data.items');
        $this->assertGreaterThanOrEqual(1, count($items));
    }

    public function test_first_party_ownership_type_is_set(): void
    {
        $r = $this->seedSuvakamana();
        $this->assertSame('first_party', $r->ownership_type);
        $this->assertTrue($r->isFirstParty());
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seedSuvakamana();
        $this->seedSuvakamana();
        $this->assertSame(1, Restaurant::query()->where('slug', 'suvakamana-restaurant')->count());
    }

    public function test_suvakamana_item_addable_to_guest_cart(): void
    {
        $this->seedSuvakamana();
        $item = MenuItem::query()->where('slug', 'dal-bhat')->firstOrFail();

        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $item->public_id,
            'quantity' => 1,
        ])->assertStatus(201);
    }

    public function test_platform_restaurant_returns_404_when_not_seeded(): void
    {
        $this->getJson('/api/v1/public/platform-restaurant')->assertStatus(404);
    }
}
