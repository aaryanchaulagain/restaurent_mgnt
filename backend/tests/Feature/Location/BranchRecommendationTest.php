<?php

namespace Tests\Feature\Location;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Cart;
use App\Models\CustomerAddress;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantAddress;
use App\Models\RestaurantServiceArea;
use App\Models\Role;
use App\Models\User;
use App\Support\BranchStatuses;
use App\Support\GeoDistance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchRecommendationTest extends TestCase
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

    public function test_manual_postcode_recommends_eligible_branch(): void
    {
        [$business, $near, $far] = $this->twoBranchGrocery();

        $this->serviceArea($near->restaurant, '4000');
        $this->serviceArea($far->restaurant, '5000');

        $res = $this->postJson("/api/v1/public/businesses/{$business->slug}/branch-recommendations", [
            'fulfilment' => 'delivery',
            'postcode' => '4000',
        ])->assertOk();

        $this->assertSame($near->public_id, $res->json('data.recommended_branch_public_id'));
        $this->assertTrue($res->json('data.branches.0.delivery_eligible'));
        $this->assertFalse($res->json('data.location.coordinates_used'));
        $this->assertArrayNotHasKey('latitude', $res->json('data.location'));
        $this->assertNull($res->json('data.branches.0.distance_km'));
    }

    public function test_nearest_outside_service_area_is_not_recommended_as_eligible(): void
    {
        [$business, $near, $far] = $this->twoBranchGrocery();

        // Near branch is geographically closer but only serves 9999.
        $near->update(['latitude' => -27.47, 'longitude' => 153.025]);
        $far->update(['latitude' => -27.55, 'longitude' => 153.10]);
        $this->serviceArea($near->restaurant, '9999');
        $this->serviceArea($far->restaurant, '4000');

        $res = $this->postJson("/api/v1/public/businesses/{$business->slug}/branch-recommendations", [
            'fulfilment' => 'delivery',
            'postcode' => '4000',
            'latitude' => -27.4698,
            'longitude' => 153.0251,
        ])->assertOk();

        $byId = collect($res->json('data.branches'))->keyBy('public_id');
        $this->assertFalse($byId[$near->public_id]['delivery_eligible']);
        $this->assertContains('OUTSIDE_SERVICE_AREA', $byId[$near->public_id]['eligibility_reasons']);
        $this->assertTrue($byId[$far->public_id]['delivery_eligible']);
        $this->assertSame($far->public_id, $res->json('data.recommended_branch_public_id'));
        $this->assertTrue($byId[$near->public_id]['distance_km'] < $byId[$far->public_id]['distance_km']);
    }

    public function test_browser_coordinates_used_without_persistence(): void
    {
        [$business, $branch] = $this->singleBranch();
        $branch->update(['latitude' => -33.8688, 'longitude' => 151.2093]);
        $this->serviceArea($branch->restaurant, '2000');

        $before = CustomerAddress::query()->count();

        $res = $this->postJson("/api/v1/public/businesses/{$business->slug}/branch-recommendations", [
            'fulfilment' => 'delivery',
            'postcode' => '2000',
            'latitude' => -33.87,
            'longitude' => 151.21,
        ])->assertOk();

        $this->assertTrue($res->json('data.location.coordinates_used'));
        $this->assertNotNull($res->json('data.branches.0.distance_km'));
        $this->assertSame($before, CustomerAddress::query()->count());
        $this->assertSame(0, Cart::query()->count());
    }

    public function test_invalid_latitude_rejected(): void
    {
        [$business] = $this->singleBranch();

        $this->postJson("/api/v1/public/businesses/{$business->slug}/branch-recommendations", [
            'fulfilment' => 'delivery',
            'postcode' => '2000',
            'latitude' => 120,
            'longitude' => 151.21,
        ])->assertStatus(422);
    }

    public function test_missing_delivery_location_returns_safe_validation(): void
    {
        [$business] = $this->singleBranch();

        $this->postJson("/api/v1/public/businesses/{$business->slug}/branch-recommendations", [
            'fulfilment' => 'delivery',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'ADDRESS_POSTCODE_REQUIRED');
    }

    public function test_saved_address_ownership_enforced(): void
    {
        [$business, $branch] = $this->singleBranch();
        $this->serviceArea($branch->restaurant, '2000');

        $owner = $this->customer();
        $other = $this->customer();
        $address = CustomerAddress::query()->create([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $owner->id,
            'recipient_name' => 'Owner',
            'address_line_1' => '1 Test',
            'suburb' => 'Sydney',
            'state' => 'NSW',
            'postcode' => '2000',
            'country' => 'AU',
        ]);

        Sanctum::actingAs($other);
        $this->postJson("/api/v1/customer/businesses/{$business->slug}/branch-recommendations", [
            'fulfilment' => 'delivery',
            'address_public_id' => $address->public_id,
        ])->assertNotFound()
            ->assertJsonPath('code', 'ADDRESS_NOT_FOUND');

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/customer/businesses/{$business->slug}/branch-recommendations", [
            'fulfilment' => 'delivery',
            'address_public_id' => $address->public_id,
        ])->assertOk()
            ->assertJsonPath('data.recommended_branch_public_id', $branch->public_id);
    }

    public function test_pickup_does_not_require_service_area(): void
    {
        [$business, $branch] = $this->singleBranch();
        $branch->restaurant->update(['pickup_enabled' => true, 'restaurant_delivery_enabled' => false]);

        $this->postJson("/api/v1/public/businesses/{$business->slug}/branch-recommendations", [
            'fulfilment' => 'pickup',
        ])->assertOk()
            ->assertJsonPath('data.branches.0.pickup_eligible', true)
            ->assertJsonPath('data.recommended_branch_public_id', $branch->public_id);
    }

    public function test_suspended_branch_excluded(): void
    {
        [$business, $active] = $this->singleBranch();
        $this->serviceArea($active->restaurant, '2000');
        $this->extraBranch($business, 'Hidden', BranchStatuses::SUSPENDED, RestaurantStatus::Suspended);

        $res = $this->postJson("/api/v1/public/businesses/{$business->slug}/branch-recommendations", [
            'fulfilment' => 'delivery',
            'postcode' => '2000',
        ])->assertOk();

        $this->assertCount(1, $res->json('data.branches'));
        $this->assertSame($active->public_id, $res->json('data.branches.0.public_id'));
    }

    public function test_temporarily_closed_visible_but_not_recommended(): void
    {
        [$business, $open, $closed] = $this->twoBranchGrocery();
        $this->serviceArea($open->restaurant, '4000');
        $this->serviceArea($closed->restaurant, '4000');
        $closed->update(['status' => BranchStatuses::PAUSED, 'accepting_orders' => false]);
        $closed->restaurant->update([
            'status' => RestaurantStatus::TemporarilyClosed,
            'accepting_orders' => false,
        ]);

        $res = $this->postJson("/api/v1/public/businesses/{$business->slug}/branch-recommendations", [
            'fulfilment' => 'delivery',
            'postcode' => '4000',
        ])->assertOk();

        $byId = collect($res->json('data.branches'))->keyBy('public_id');
        $this->assertTrue($byId->has($closed->public_id));
        $this->assertFalse($byId[$closed->public_id]['delivery_eligible']);
        $this->assertContains('BRANCH_NOT_ACCEPTING_ORDERS', $byId[$closed->public_id]['eligibility_reasons']);
        $this->assertSame($open->public_id, $res->json('data.recommended_branch_public_id'));
    }

    public function test_haversine_is_deterministic_and_rounded(): void
    {
        $km = GeoDistance::haversineKm(-27.4698, 153.0251, -27.4700, 153.0253);
        $this->assertEqualsWithDelta($km, GeoDistance::haversineKm(-27.4698, 153.0251, -27.4700, 153.0253), 0.0000001);
        $this->assertSame(GeoDistance::roundKm(3.44), 3.4);
    }

    public function test_client_distance_and_ids_prohibited(): void
    {
        [$business] = $this->singleBranch();

        $this->postJson("/api/v1/public/businesses/{$business->slug}/branch-recommendations", [
            'fulfilment' => 'delivery',
            'postcode' => '2000',
            'distance_km' => 0.1,
            'branch_id' => 1,
        ])->assertStatus(422);
    }

    public function test_public_endpoint_ignores_tenant_headers(): void
    {
        [$business, $branch] = $this->singleBranch();
        $this->serviceArea($branch->restaurant, '2000');
        [, $otherBranch] = $this->singleBranch('other-biz');

        $this->withHeaders([
            'X-Branch-Id' => $otherBranch->public_id,
            'X-Restaurant-Id' => $otherBranch->restaurant->public_id,
        ])->postJson("/api/v1/public/businesses/{$business->slug}/branch-recommendations", [
            'fulfilment' => 'delivery',
            'postcode' => '2000',
        ])->assertOk()
            ->assertJsonPath('data.recommended_branch_public_id', $branch->public_id);
    }

    public function test_recommendation_does_not_modify_cart(): void
    {
        [$business, $branch] = $this->singleBranch();
        $this->serviceArea($branch->restaurant, '2000');
        $user = $this->customer();
        Cart::query()->create([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $user->id,
            'restaurant_id' => $branch->restaurant_id,
            'status' => 'active',
            'currency' => 'AUD',
            'version' => 3,
        ]);

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/customer/businesses/{$business->slug}/branch-recommendations", [
            'fulfilment' => 'delivery',
            'postcode' => '2000',
        ])->assertOk();

        $this->assertSame(3, Cart::query()->where('customer_id', $user->id)->value('version'));
        $this->assertSame('active', Cart::query()->where('customer_id', $user->id)->value('status'));
    }

    public function test_another_business_branch_never_considered(): void
    {
        [$businessA, $branchA] = $this->singleBranch('biz-a');
        [$businessB, $branchB] = $this->singleBranch('biz-b');
        $this->serviceArea($branchA->restaurant, '2000');
        $this->serviceArea($branchB->restaurant, '2000');

        $res = $this->postJson("/api/v1/public/businesses/{$businessA->slug}/branch-recommendations", [
            'fulfilment' => 'delivery',
            'postcode' => '2000',
        ])->assertOk();

        $ids = collect($res->json('data.branches'))->pluck('public_id');
        $this->assertTrue($ids->contains($branchA->public_id));
        $this->assertFalse($ids->contains($branchB->public_id));
    }

    public function test_branch_without_delivery_not_delivery_eligible(): void
    {
        [$business, $branch] = $this->singleBranch();
        $branch->restaurant->update(['restaurant_delivery_enabled' => false, 'pickup_enabled' => true]);

        $res = $this->postJson("/api/v1/public/businesses/{$business->slug}/branch-recommendations", [
            'fulfilment' => 'delivery',
            'postcode' => '2000',
        ])->assertOk();

        $this->assertFalse($res->json('data.branches.0.delivery_eligible'));
        $this->assertContains('DELIVERY_NOT_SUPPORTED', $res->json('data.branches.0.eligibility_reasons'));
        $this->assertNull($res->json('data.recommended_branch_public_id'));
    }

    private function customer(): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'status' => 'active']);
        $user->roles()->attach(Role::query()->where('slug', 'customer')->value('id'));

        return $user;
    }

    /** @return array{0: Business, 1: Branch} */
    private function singleBranch(string $slug = 'single'): array
    {
        [$business, $branch] = $this->linked($slug, 'Main');

        return [$business, $branch];
    }

    /** @return array{0: Business, 1: Branch, 2: Branch} */
    private function twoBranchGrocery(): array
    {
        [$business, $a] = $this->linked('aryan-grocery', 'Itahari');
        $b = $this->extraBranch($business, 'Dharan', BranchStatuses::ACTIVE, RestaurantStatus::Active);

        return [$business, $a, $b];
    }

    /** @return array{0: Business, 1: Branch} */
    private function linked(string $slug, string $branchName): array
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
            'slug' => $business->slug.'-'.Str::slug($branchName),
            'legal_business_name' => $business->name.' Pty Ltd',
            'trading_name' => $branchName,
            'status' => RestaurantStatus::Active,
            'published_at' => now(),
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
            'accepting_orders' => true,
            'pickup_enabled' => true,
            'restaurant_delivery_enabled' => true,
            'ownership_type' => 'third_party',
            'vendor_type' => 'grocery',
        ]);

        $branch = Branch::query()->create([
            'public_id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'restaurant_id' => $restaurant->id,
            'name' => $branchName.' Branch',
            'code' => strtoupper(Str::random(4)),
            'status' => BranchStatuses::ACTIVE,
            'accepting_orders' => true,
            'is_default' => true,
            'city' => $branchName,
            'postcode' => '4000',
            'timezone' => 'Australia/Sydney',
            'sort_order' => 0,
        ]);
        $restaurant->forceFill(['branch_id' => $branch->id])->save();

        RestaurantAddress::query()->create([
            'restaurant_id' => $restaurant->id,
            'address_line_1' => '1 Main St',
            'suburb' => $branchName,
            'state' => 'QLD',
            'postcode' => '4000',
            'country' => 'AU',
            'is_primary' => true,
        ]);

        return [$business, $branch->fresh(['restaurant'])];
    }

    private function extraBranch(
        Business $business,
        string $name,
        string $branchStatus,
        RestaurantStatus $restaurantStatus,
    ): Branch {
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'slug' => $business->slug.'-'.Str::slug($name).'-'.Str::lower(Str::random(3)),
            'legal_business_name' => $business->name.' Pty Ltd',
            'trading_name' => $name,
            'status' => $restaurantStatus,
            'published_at' => in_array($restaurantStatus, [RestaurantStatus::Active, RestaurantStatus::TemporarilyClosed], true) ? now() : null,
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
            'accepting_orders' => $restaurantStatus === RestaurantStatus::Active,
            'pickup_enabled' => true,
            'restaurant_delivery_enabled' => true,
            'ownership_type' => 'third_party',
            'vendor_type' => 'grocery',
            'suspended_at' => $restaurantStatus === RestaurantStatus::Suspended ? now() : null,
        ]);

        $branch = Branch::query()->create([
            'public_id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'restaurant_id' => $restaurant->id,
            'name' => $name.' Branch',
            'code' => strtoupper(Str::random(4)),
            'status' => $branchStatus,
            'accepting_orders' => $branchStatus === BranchStatuses::ACTIVE,
            'is_default' => false,
            'city' => $name,
            'timezone' => 'Australia/Sydney',
            'sort_order' => 5,
            'suspended_at' => $branchStatus === BranchStatuses::SUSPENDED ? now() : null,
        ]);
        $restaurant->forceFill(['branch_id' => $branch->id])->save();

        RestaurantAddress::query()->create([
            'restaurant_id' => $restaurant->id,
            'address_line_1' => '2 Side St',
            'suburb' => $name,
            'state' => 'QLD',
            'postcode' => '4001',
            'country' => 'AU',
            'is_primary' => true,
        ]);

        return $branch->fresh(['restaurant']);
    }

    private function serviceArea(Restaurant $restaurant, string $postcode): void
    {
        RestaurantServiceArea::query()->create([
            'restaurant_id' => $restaurant->id,
            'type' => 'postcode',
            'postcode' => $postcode,
            'is_active' => true,
            'delivery_fee_cents' => 500,
            'minimum_order_cents' => 0,
        ]);
    }
}
