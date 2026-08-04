<?php

namespace Tests\Feature\Cart;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Cart;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\Support\BranchStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CartBranchLockTest extends TestCase
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

    public function test_same_branch_items_share_one_cart_with_branch_summary(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);
        [$business, $branch, $restaurant, $itemA] = $this->branchMenu('same-a');
        $itemB = $this->extraItem($restaurant, 'Oil');

        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $itemA->public_id,
            'quantity' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.cart.branch.public_id', $branch->public_id)
            ->assertJsonPath('data.cart.business.slug', $business->slug);

        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $itemB->public_id,
            'quantity' => 1,
        ])->assertCreated()
            ->assertJsonCount(2, 'data.cart.items');

        $payload = $this->getJson('/api/v1/cart')->assertOk()->json('data.cart');
        $this->assertArrayNotHasKey('quantity_on_hand', $payload['items'][0] ?? []);
        $this->assertSame($branch->public_id, $payload['branch']['public_id']);
    }

    public function test_sibling_branch_returns_cart_branch_conflict_without_clearing(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);
        [$business, $branchA, , $itemA] = $this->branchMenu('itahari');
        [, $branchB, , $itemB] = $this->secondBranch($business, 'Dharan');

        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $itemA->public_id,
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $itemB->public_id,
            'quantity' => 1,
        ])->assertStatus(409)
            ->assertJsonPath('code', 'CART_BRANCH_CONFLICT')
            ->assertJsonPath('data.current_cart.branch_name', $branchA->name)
            ->assertJsonPath('data.requested_branch.branch_name', $branchB->name);

        $this->getJson('/api/v1/cart')->assertOk()
            ->assertJsonCount(1, 'data.cart.items')
            ->assertJsonPath('data.cart.branch.public_id', $branchA->public_id);
    }

    public function test_spoofed_tenant_headers_do_not_change_cart_branch(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);
        [$business, $branchA, , $itemA] = $this->branchMenu('hdr-a');
        [, $branchB] = $this->secondBranch($business, 'Hdr B');

        $this->withHeaders([
            'X-Branch-Id' => $branchB->public_id,
            'X-Restaurant-Id' => $branchB->restaurant->public_id,
        ])->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $itemA->public_id,
            'quantity' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.cart.branch.public_id', $branchA->public_id);
    }

    public function test_clear_releases_lock_then_other_branch_can_add(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);
        [$business, , , $itemA] = $this->branchMenu('clear-a');
        [, $branchB, , $itemB] = $this->secondBranch($business, 'Clear B');

        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $itemA->public_id,
            'quantity' => 1,
        ])->assertCreated();

        $this->deleteJson('/api/v1/cart')->assertOk()
            ->assertJsonPath('data.cart', null);

        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $itemB->public_id,
            'quantity' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.cart.branch.public_id', $branchB->public_id);
    }

    public function test_removing_last_item_releases_branch_lock(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);
        [, , , $item] = $this->branchMenu('empty-lock');

        $add = $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $item->public_id,
            'quantity' => 1,
        ])->assertCreated();

        $lineId = $add->json('data.cart.items.0.public_id');
        $this->deleteJson("/api/v1/cart/items/{$lineId}")->assertOk()
            ->assertJsonPath('data.cart', null);
    }

    public function test_temporarily_closed_branch_rejects_add(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);
        [, $branch, $restaurant, $item] = $this->branchMenu('paused-add');
        $branch->update(['status' => BranchStatuses::PAUSED, 'accepting_orders' => false]);
        $restaurant->update([
            'status' => RestaurantStatus::TemporarilyClosed,
            'accepting_orders' => false,
        ]);

        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $item->public_id,
            'quantity' => 1,
        ])->assertStatus(422)
            ->assertJsonPath('code', 'CART_BRANCH_NOT_ACCEPTING_ORDERS');
    }

    public function test_quote_rejects_not_accepting_branch(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);
        [, $branch, $restaurant, $item] = $this->branchMenu('quote-pause');

        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $item->public_id,
            'quantity' => 1,
        ])->assertCreated();

        $branch->update(['status' => BranchStatuses::PAUSED, 'accepting_orders' => false]);
        $restaurant->update([
            'status' => RestaurantStatus::TemporarilyClosed,
            'accepting_orders' => false,
        ]);

        $this->postJson('/api/v1/checkout/quote', [
            'fulfilment_type' => 'pickup',
        ])->assertStatus(422);
    }

    public function test_replace_restaurant_flag_switches_branch(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);
        [$business, $branchA, , $itemA] = $this->branchMenu('rep-a');
        [, $branchB, , $itemB] = $this->secondBranch($business, 'Rep B');

        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $itemA->public_id,
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $itemB->public_id,
            'quantity' => 1,
            'replace_restaurant' => true,
        ])->assertCreated()
            ->assertJsonCount(1, 'data.cart.items')
            ->assertJsonPath('data.cart.branch.public_id', $branchB->public_id);

        $this->assertTrue(
            Cart::query()->where('customer_id', $user->id)->where('status', 'abandoned')->exists()
        );
        $this->assertNotEquals($branchA->public_id, $branchB->public_id);
    }

    public function test_quote_rejects_mismatched_client_branch_id(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);
        [$business, , , $itemA] = $this->branchMenu('quote-mismatch');
        [, $branchB] = $this->secondBranch($business, 'Other');

        $this->postJson('/api/v1/cart/items', [
            'menu_item_public_id' => $itemA->public_id,
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson('/api/v1/checkout/quote', [
            'fulfilment_type' => 'pickup',
            'branch_public_id' => $branchB->public_id,
        ])->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'CHECKOUT_CART_BRANCH_CHANGED');
    }

    private function customer(): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'status' => 'active']);
        $user->roles()->attach(Role::query()->where('slug', 'customer')->value('id'));

        return $user;
    }

    /** @return array{0: Business, 1: Branch, 2: Restaurant, 3: MenuItem} */
    private function branchMenu(string $slug): array
    {
        $business = Business::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => Str::headline($slug),
            'slug' => $slug.'-'.Str::lower(Str::random(4)),
            'business_type' => 'grocery',
            'ownership_type' => 'third_party',
            'status' => 'active',
        ]);

        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'slug' => $business->slug.'-main',
            'legal_business_name' => $business->name.' Pty Ltd',
            'trading_name' => $business->name.' Main',
            'status' => RestaurantStatus::Active,
            'published_at' => now(),
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
            'accepting_orders' => true,
            'pickup_enabled' => true,
            'restaurant_delivery_enabled' => true,
            'minimum_order_cents' => 0,
            'ownership_type' => 'third_party',
            'vendor_type' => 'grocery',
        ]);

        $branch = Branch::query()->create([
            'public_id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'restaurant_id' => $restaurant->id,
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'status' => BranchStatuses::ACTIVE,
            'accepting_orders' => true,
            'is_default' => true,
            'city' => 'Itahari',
            'state' => 'Koshi',
            'timezone' => 'Australia/Sydney',
            'sort_order' => 0,
        ]);
        $restaurant->forceFill(['branch_id' => $branch->id])->save();

        return [$business, $branch->fresh(), $restaurant->fresh(), $this->extraItem($restaurant, 'Rice')];
    }

    /** @return array{0: Business, 1: Branch, 2: Restaurant, 3: MenuItem} */
    private function secondBranch(Business $business, string $name): array
    {
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'slug' => $business->slug.'-'.Str::slug($name).'-'.Str::lower(Str::random(3)),
            'legal_business_name' => $business->name.' Pty Ltd',
            'trading_name' => $name,
            'status' => RestaurantStatus::Active,
            'published_at' => now(),
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
            'accepting_orders' => true,
            'pickup_enabled' => true,
            'minimum_order_cents' => 0,
            'ownership_type' => 'third_party',
            'vendor_type' => 'grocery',
        ]);

        $branch = Branch::query()->create([
            'public_id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'restaurant_id' => $restaurant->id,
            'name' => $name.' Branch',
            'code' => strtoupper(Str::random(4)),
            'status' => BranchStatuses::ACTIVE,
            'accepting_orders' => true,
            'is_default' => false,
            'city' => $name,
            'timezone' => 'Australia/Sydney',
            'sort_order' => 5,
        ]);
        $restaurant->forceFill(['branch_id' => $branch->id])->save();

        return [$business->fresh(), $branch->fresh(['restaurant']), $restaurant->fresh(), $this->extraItem($restaurant, $name.' Milk')];
    }

    private function extraItem(Restaurant $restaurant, string $name): MenuItem
    {
        $menu = Menu::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'is_default' => true],
            ['public_id' => (string) Str::uuid(), 'name' => 'Main', 'status' => 'active'],
        );
        $cat = MenuCategory::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'name' => 'Grocery'],
            ['public_id' => (string) Str::uuid(), 'menu_id' => $menu->id, 'is_active' => true],
        );

        return MenuItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'menu_category_id' => $cat->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'base_price_cents' => 1250,
            'is_active' => true,
            'is_available' => true,
        ]);
    }
}
