<?php

namespace Tests\Feature\Public;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Restaurant;
use App\Services\Business\BusinessHierarchyMigrator;
use App\Services\Branch\BranchProvisionService;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\BusinessRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformRestaurantFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_restaurant_returns_false_platform_flag(): void
    {
        Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'normal-kitchen',
            'legal_business_name' => 'Normal Kitchen',
            'trading_name' => 'Normal Kitchen',
            'status' => RestaurantStatus::Active,
            'published_at' => now(),
            'ownership_type' => 'third_party',
            'is_platform_restaurant' => false,
            'accepting_orders' => true,
            'pickup_enabled' => true,
        ]);

        $response = $this->getJson('/api/v1/public/restaurants/normal-kitchen')->assertOk();
        $this->assertFalse($response->json('data.restaurant.is_platform_restaurant'));
        $this->assertIsBool($response->json('data.restaurant.is_platform_restaurant'));
    }

    public function test_hierarchy_migration_preserves_platform_flag(): void
    {
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'suvakamana-restaurant',
            'legal_business_name' => 'Suvakamana Restaurant',
            'trading_name' => 'Suvakamana Restaurant',
            'status' => RestaurantStatus::Active,
            'published_at' => now(),
            'ownership_type' => 'third_party',
            'is_platform_restaurant' => true,
            'accepting_orders' => true,
        ]);

        app(BusinessHierarchyMigrator::class)->migrateRestaurant($restaurant->fresh());
        $restaurant->refresh();

        $this->assertTrue((bool) $restaurant->is_platform_restaurant);
        $this->assertNotNull($restaurant->business_id);
        $this->assertNotNull($restaurant->branch_id);
    }

    public function test_branch_provisioning_does_not_overwrite_existing_platform_flag(): void
    {
        $this->seedMinimalRoles();
        $platform = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'suvakamana-restaurant',
            'legal_business_name' => 'Suvakamana Restaurant',
            'trading_name' => 'Suvakamana Restaurant',
            'status' => RestaurantStatus::Active,
            'published_at' => now(),
            'ownership_type' => 'third_party',
            'is_platform_restaurant' => true,
            'accepting_orders' => true,
        ]);

        $result = app(BusinessHierarchyMigrator::class)->migrateRestaurant($platform->fresh());
        $business = $result['business'];
        $owner = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        BusinessUser::query()->create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'role' => BusinessRoles::BUSINESS_OWNER,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $business->forceFill(['owner_user_id' => $owner->id])->save();

        $created = app(BranchProvisionService::class)->create($business, [
            'name' => 'Second Branch',
            'code' => 'SECOND',
            'status' => 'draft',
        ], $owner);

        $platform->refresh();
        $this->assertTrue((bool) $platform->is_platform_restaurant);
        $this->assertFalse((bool) $created['restaurant']->is_platform_restaurant);
    }

    private function seedMinimalRoles(): void
    {
        foreach (['restaurant_owner', 'restaurant_manager', 'restaurant_staff', 'super_admin'] as $slug) {
            Role::query()->firstOrCreate(['slug' => $slug], ['name' => $slug, 'guard' => 'web']);
        }
        foreach (['view_restaurant_dashboard', 'manage_restaurants'] as $slug) {
            Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }
    }
}
