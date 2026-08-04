<?php

namespace Tests\Feature\Cart;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
        $this->disableCookieEncryption();
    }

    public function test_customer_can_add_item_and_get_cart(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'customer')->firstOrFail();
        $user->roles()->attach($role->id);
        Sanctum::actingAs($user);

        [$restaurant, $item] = $this->liveMenu();
        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $item->public_id,
            'quantity' => 2,
        ])->assertCreated();

        $this->getJson('/api/v1/cart')->assertOk()
            ->assertJsonPath('data.cart.restaurant.slug', $restaurant->slug)
            ->assertJsonPath('data.pricing.subtotal_cents', 2500);
    }

    public function test_restaurant_conflict_returns_409(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'customer')->firstOrFail();
        $user->roles()->attach($role->id);
        Sanctum::actingAs($user);

        [, $item1] = $this->liveMenu('kitchen-a');
        [, $item2] = $this->liveMenu('kitchen-b');

        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $item1->public_id,
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $item2->public_id,
            'quantity' => 1,
        ])->assertStatus(409)
            ->assertJsonPath('errors.code.0', 'CART_BRANCH_CONFLICT');
    }

    public function test_unauthenticated_cannot_add_to_cart(): void
    {
        [, $item] = $this->liveMenu('guest-cart');

        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $item->public_id,
            'quantity' => 1,
        ])->assertUnauthorized();
    }

    public function test_unauthenticated_cannot_view_cart(): void
    {
        $this->getJson('/api/v1/cart')->assertUnauthorized();
    }

    public function test_authenticated_cart_is_customer_scoped(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'customer')->firstOrFail();
        $user->roles()->attach($role->id);
        Sanctum::actingAs($user);

        [, $item] = $this->liveMenu('guest-vs-customer');
        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $item->public_id,
            'quantity' => 1,
        ])->assertCreated();

        $this->assertNotNull(Cart::query()->where('customer_id', $user->id)->first());
    }

    public function test_client_price_is_ignored_by_server(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'customer')->firstOrFail();
        $user->roles()->attach($role->id);
        Sanctum::actingAs($user);

        [, $item] = $this->liveMenu();
        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $item->public_id,
            'quantity' => 1,
            'client_price_cents' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.pricing.subtotal_cents', 1250);
    }

    public function test_inactive_item_rejected(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'customer')->firstOrFail();
        $user->roles()->attach($role->id);
        Sanctum::actingAs($user);

        [, $item] = $this->liveMenu();
        $item->update(['is_active' => false]);
        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $item->public_id,
            'quantity' => 1,
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
    private function liveMenu(string $slug = 'cart-test'): array
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
