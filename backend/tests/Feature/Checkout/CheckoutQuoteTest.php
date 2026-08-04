<?php

namespace Tests\Feature\Checkout;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantServiceArea;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutQuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_pickup_quote_for_valid_cart(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'customer')->firstOrFail();
        $user->roles()->attach($role->id);
        Sanctum::actingAs($user);

        [, $item] = $this->liveMenu();
        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $item->public_id,
            'quantity' => 2,
        ])->assertCreated();

        $this->postJson('/api/v1/checkout/quote', [
            'fulfilment_type' => 'pickup',
            'terms_accepted' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.quote.fulfilment_type', 'pickup')
            ->assertJsonPath('data.quote.pricing.subtotal_cents', 2500);
    }

    public function test_third_party_delivery_rejected(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'customer')->firstOrFail();
        $user->roles()->attach($role->id);
        Sanctum::actingAs($user);

        [, $item] = $this->liveMenu();
        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $item->public_id,
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson('/api/v1/checkout/quote', [
            'fulfilment_type' => 'third_party_delivery',
        ])->assertStatus(422);
    }

    public function test_unsupported_postcode_rejected_for_delivery(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'customer')->firstOrFail();
        $user->roles()->attach($role->id);
        Sanctum::actingAs($user);

        [, $item] = $this->liveMenu('delivery-quote');
        $restaurant = Restaurant::query()->where('slug', 'delivery-quote')->firstOrFail();
        RestaurantServiceArea::query()->create([
            'restaurant_id' => $restaurant->id,
            'type' => 'postcode',
            'postcode' => '2000',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $item->public_id,
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson('/api/v1/checkout/quote', [
            'fulfilment_type' => 'restaurant_delivery',
            'address' => ['postcode' => '3000', 'address_line_1' => '1 Test St', 'suburb' => 'Melbourne', 'state' => 'VIC'],
        ])->assertStatus(422);
    }

    private function seedPermissions(): void
    {
        foreach (config('suvakamana.permissions') as $slug) {
            Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }
        Role::query()->firstOrCreate(['slug' => 'customer'], ['name' => 'Customer', 'guard' => 'web']);
    }

    /** @return array{0: Restaurant, 1: MenuItem} */
    private function liveMenu(string $slug = 'quote-test'): array
    {
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => $slug,
            'legal_business_name' => 'Test Pty Ltd',
            'trading_name' => 'Test Kitchen',
            'status' => RestaurantStatus::Active,
            'published_at' => now(),
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
            'accepting_orders' => true,
            'pickup_enabled' => true,
            'restaurant_delivery_enabled' => true,
        ]);
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
        $item = MenuItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'menu_category_id' => $cat->id,
            'name' => 'Test Item',
            'slug' => 'test-'.$slug,
            'base_price_cents' => 1250,
            'is_active' => true,
            'is_available' => true,
        ]);

        return [$restaurant, $item];
    }
}
