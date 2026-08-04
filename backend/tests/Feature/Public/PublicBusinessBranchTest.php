<?php

namespace Tests\Feature\Public;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Support\BranchStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicBusinessBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_branch_is_visible_and_menu_loads(): void
    {
        [$business, $branch, $restaurant] = $this->businessWithBranch('alpha');
        $item = $this->menuItem($restaurant, 'Alpha Curry');

        $this->getJson("/api/v1/public/businesses/{$business->slug}")
            ->assertOk()
            ->assertJsonPath('data.branch_count', 1)
            ->assertJsonPath('data.preferred_branch_public_id', $branch->public_id)
            ->assertJsonPath('data.branches.0.public_id', $branch->public_id)
            ->assertJsonPath('data.branches.0.accepting_orders', true);

        $menu = $this->getJson("/api/v1/public/businesses/{$business->slug}/branches/{$branch->public_id}/menu")
            ->assertOk()
            ->json('data');

        $this->assertSame($restaurant->slug, $menu['restaurant']['slug']);
        $this->assertSame($item->public_id, $menu['items'][0]['public_id']);
        $this->assertArrayNotHasKey('quantity_on_hand', $menu['items'][0]);
        $this->assertArrayNotHasKey('quantity_reserved', $menu['items'][0]);
        $this->assertArrayNotHasKey('cost_price_cents', $menu['items'][0]);
    }

    public function test_paused_branch_remains_visible_but_not_orderable(): void
    {
        [$business, $branch, $restaurant] = $this->businessWithBranch('paused-biz');
        $branch->update(['status' => BranchStatuses::PAUSED, 'accepting_orders' => false]);
        $restaurant->update([
            'status' => RestaurantStatus::TemporarilyClosed,
            'accepting_orders' => false,
        ]);

        $this->getJson("/api/v1/public/businesses/{$business->slug}/branches")
            ->assertOk()
            ->assertJsonPath('data.branches.0.public_id', $branch->public_id)
            ->assertJsonPath('data.branches.0.is_temporarily_closed', true)
            ->assertJsonPath('data.branches.0.accepting_orders', false);

        $this->getJson("/api/v1/public/businesses/{$business->slug}/branches/{$branch->public_id}")
            ->assertOk()
            ->assertJsonPath('data.branch.accepting_orders', false);

        $this->getJson("/api/v1/public/businesses/{$business->slug}/branches/{$branch->public_id}/menu")
            ->assertOk();
    }

    public function test_suspended_and_inactive_branches_are_hidden(): void
    {
        [$business, $active, $restaurant] = $this->businessWithBranch('hide-biz');
        $this->extraBranch($business, 'Suspended Spot', BranchStatuses::SUSPENDED, RestaurantStatus::Suspended);
        $this->extraBranch($business, 'Inactive Spot', BranchStatuses::INACTIVE, RestaurantStatus::Disabled);
        $this->extraBranch($business, 'Draft Spot', BranchStatuses::DRAFT, RestaurantStatus::PendingSetup, published: false);

        $response = $this->getJson("/api/v1/public/businesses/{$business->slug}/branches")->assertOk();
        $ids = collect($response->json('data.branches'))->pluck('public_id');
        $this->assertTrue($ids->contains($active->public_id));
        $this->assertCount(1, $ids);
    }

    public function test_branch_from_another_business_cannot_be_loaded(): void
    {
        [$businessA, $branchA] = $this->businessWithBranch('biz-a');
        [$businessB, $branchB] = $this->businessWithBranch('biz-b');

        $this->getJson("/api/v1/public/businesses/{$businessA->slug}/branches/{$branchB->public_id}")
            ->assertNotFound();
        $this->getJson("/api/v1/public/businesses/{$businessA->slug}/branches/{$branchB->public_id}/menu")
            ->assertNotFound();
        $this->getJson("/api/v1/public/businesses/{$businessA->slug}/branches/{$branchA->public_id}")
            ->assertOk();
    }

    public function test_branch_restaurant_mismatch_returns_safe_404(): void
    {
        [$business, $branch, $restaurant] = $this->businessWithBranch('mismatch');

        // Break mutual FK: restaurant no longer points at this branch.
        $restaurant->forceFill(['branch_id' => null])->save();

        $this->getJson("/api/v1/public/businesses/{$business->slug}/branches/{$branch->public_id}")
            ->assertNotFound();
        $this->getJson("/api/v1/public/businesses/{$business->slug}/branches/{$branch->public_id}/menu")
            ->assertNotFound();

        // Break business ownership on the linked restaurant.
        $restaurant->forceFill([
            'branch_id' => $branch->id,
            'business_id' => Business::query()->create([
                'public_id' => (string) Str::uuid(),
                'name' => 'Other Biz',
                'slug' => 'other-biz-'.Str::lower(Str::random(4)),
                'business_type' => 'restaurant',
                'ownership_type' => 'third_party',
                'status' => 'active',
            ])->id,
        ])->save();

        $this->getJson("/api/v1/public/businesses/{$business->slug}/branches/{$branch->public_id}")
            ->assertNotFound();
    }

    public function test_branch_menus_are_isolated(): void
    {
        [$business, $branchA, $restaurantA] = $this->businessWithBranch('iso');
        $branchB = $this->extraBranch($business, 'Second', BranchStatuses::ACTIVE, RestaurantStatus::Active);
        $itemA = $this->menuItem($restaurantA, 'Only A');
        $itemB = $this->menuItem($branchB->restaurant, 'Only B');

        $menuA = $this->getJson("/api/v1/public/businesses/{$business->slug}/branches/{$branchA->public_id}/menu")
            ->assertOk()
            ->json('data.items');
        $menuB = $this->getJson("/api/v1/public/businesses/{$business->slug}/branches/{$branchB->public_id}/menu")
            ->assertOk()
            ->json('data.items');

        $this->assertSame([$itemA->public_id], collect($menuA)->pluck('public_id')->all());
        $this->assertSame([$itemB->public_id], collect($menuB)->pluck('public_id')->all());
    }

    public function test_public_endpoints_ignore_spoofed_tenant_headers(): void
    {
        [$business, $branch, $restaurant] = $this->businessWithBranch('spoof');
        $this->menuItem($restaurant, 'Spoof Item');
        [, $otherBranch, $otherRestaurant] = $this->businessWithBranch('spoof-other');
        $otherItem = $this->menuItem($otherRestaurant, 'Other Item');

        $menu = $this->withHeaders([
            'X-Branch-Id' => $otherBranch->public_id,
            'X-Restaurant-Id' => $otherRestaurant->public_id,
        ])->getJson("/api/v1/public/businesses/{$business->slug}/branches/{$branch->public_id}/menu")
            ->assertOk()
            ->json('data');

        $this->assertSame($restaurant->slug, $menu['restaurant']['slug']);
        $this->assertNotEquals($otherItem->public_id, $menu['items'][0]['public_id'] ?? null);
    }

    public function test_existing_restaurant_public_routes_still_work(): void
    {
        [, $branch, $restaurant] = $this->businessWithBranch('compat');
        $this->menuItem($restaurant, 'Compat Dish');

        $this->getJson("/api/v1/public/restaurants/{$restaurant->slug}")
            ->assertOk()
            ->assertJsonPath('data.restaurant.slug', $restaurant->slug)
            ->assertJsonPath('data.restaurant.business_slug', $branch->business->slug)
            ->assertJsonPath('data.restaurant.branch_public_id', $branch->public_id);

        $this->getJson("/api/v1/public/restaurants/{$restaurant->slug}/menu")
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'categories', 'menus', 'restaurant']]);
    }

    public function test_public_browsing_is_unauthenticated(): void
    {
        [$business, $branch] = $this->businessWithBranch('anon');

        $this->getJson("/api/v1/public/businesses/{$business->slug}")->assertOk();
        $this->getJson("/api/v1/public/businesses/{$business->slug}/branches/{$branch->public_id}")->assertOk();
    }

    public function test_temporarily_closed_restaurant_slug_is_browsable(): void
    {
        [, , $restaurant] = $this->businessWithBranch('temp-slug');
        $restaurant->update([
            'status' => RestaurantStatus::TemporarilyClosed,
            'accepting_orders' => false,
        ]);

        $this->getJson("/api/v1/public/restaurants/{$restaurant->slug}")
            ->assertOk()
            ->assertJsonPath('data.restaurant.slug', $restaurant->slug);
        $this->getJson("/api/v1/public/restaurants/{$restaurant->slug}/menu")->assertOk();
    }

    /**
     * @return array{0: Business, 1: Branch, 2: Restaurant}
     */
    private function businessWithBranch(string $slug): array
    {
        $business = Business::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => Str::headline($slug),
            'slug' => $slug.'-'.Str::lower(Str::random(4)),
            'business_type' => 'restaurant',
            'ownership_type' => 'third_party',
            'status' => 'active',
        ]);

        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'slug' => $business->slug.'-main',
            'legal_business_name' => $business->name.' Pty Ltd',
            'trading_name' => $business->name,
            'status' => RestaurantStatus::Active,
            'published_at' => now(),
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
            'accepting_orders' => true,
            'pickup_enabled' => true,
            'restaurant_delivery_enabled' => true,
            'ownership_type' => 'third_party',
            'vendor_type' => 'restaurant',
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
            'timezone' => 'Australia/Sydney',
            'city' => 'Sydney',
            'state' => 'NSW',
            'postcode' => '2000',
            'country' => 'AU',
            'sort_order' => 0,
        ]);

        $restaurant->forceFill(['branch_id' => $branch->id])->save();

        return [$business->fresh(), $branch->fresh(['restaurant', 'business']), $restaurant->fresh(['branch', 'business'])];
    }

    private function extraBranch(
        Business $business,
        string $name,
        string $branchStatus,
        RestaurantStatus $restaurantStatus,
        bool $published = true,
    ): Branch {
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'slug' => $business->slug.'-'.Str::slug($name).'-'.Str::lower(Str::random(3)),
            'legal_business_name' => $business->name.' Pty Ltd',
            'trading_name' => $name,
            'status' => $restaurantStatus,
            'published_at' => $published ? now() : null,
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
            'accepting_orders' => $branchStatus === BranchStatuses::ACTIVE,
            'pickup_enabled' => true,
            'ownership_type' => 'third_party',
            'vendor_type' => 'restaurant',
        ]);

        $branch = Branch::query()->create([
            'public_id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'restaurant_id' => $restaurant->id,
            'name' => $name,
            'code' => strtoupper(Str::random(4)),
            'status' => $branchStatus,
            'accepting_orders' => $branchStatus === BranchStatuses::ACTIVE,
            'is_default' => false,
            'timezone' => 'Australia/Sydney',
            'sort_order' => 10,
            'suspended_at' => $branchStatus === BranchStatuses::SUSPENDED ? now() : null,
        ]);

        $restaurant->forceFill(['branch_id' => $branch->id])->save();

        return $branch->fresh(['restaurant', 'business']);
    }

    private function menuItem(Restaurant $restaurant, string $name): MenuItem
    {
        $menu = Menu::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'is_default' => true],
            [
                'public_id' => (string) Str::uuid(),
                'name' => 'Main',
                'status' => 'active',
            ],
        );
        $cat = MenuCategory::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'name' => 'Mains'],
            [
                'public_id' => (string) Str::uuid(),
                'menu_id' => $menu->id,
                'is_active' => true,
            ],
        );

        return MenuItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'menu_category_id' => $cat->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'base_price_cents' => 1500,
            'cost_price_cents' => 400,
            'is_active' => true,
            'is_available' => true,
        ]);
    }
}
