<?php

namespace Tests\Feature\Admin;

use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminRestaurantProvisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_provision_restaurant_and_owner(): void
    {
        $admin = $this->superAdmin();

        Sanctum::actingAs($admin);
        Session::put('mfa.verified', true);

        $response = $this->postJson('/api/v1/admin/restaurants/provision', [
            'trading_name' => 'Sold Panels Kitchen',
            'legal_business_name' => 'Sold Panels Kitchen Pty Ltd',
            'commission_rate' => 10,
            'activate_now' => true,
            'owner' => [
                'first_name' => 'Sold',
                'last_name' => 'Owner',
                'email' => 'sold-owner@example.com',
                'password' => 'Password1!',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.owner.email', 'sold-owner@example.com')
            ->assertJsonPath('data.restaurant.status', 'active')
            ->assertJsonPath('data.temporary_password', 'Password1!');

        $restaurant = Restaurant::query()->where('trading_name', 'Sold Panels Kitchen')->first();
        $this->assertNotNull($restaurant);
        $this->assertNotNull($restaurant->business_id);
        $this->assertNotNull($restaurant->branch_id);
        $this->assertDatabaseHas('restaurant_users', [
            'restaurant_id' => $restaurant->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('businesses', ['id' => $restaurant->business_id]);
        $this->assertDatabaseHas('branches', [
            'id' => $restaurant->branch_id,
            'restaurant_id' => $restaurant->id,
            'is_default' => 1,
        ]);
        $response->assertJsonPath('data.business.id', $restaurant->business_id)
            ->assertJsonPath('data.branch.id', $restaurant->branch_id);

        $owner = User::query()->where('email', 'sold-owner@example.com')->firstOrFail();
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/restaurant/ping')
            ->assertOk()
            ->assertJsonPath('data.restaurant_id', $restaurant->id);
    }

    public function test_super_admin_needs_restaurant_context_header(): void
    {
        $admin = $this->superAdmin();
        $restaurant = $this->makeRestaurant();

        Sanctum::actingAs($admin);
        Session::put('mfa.verified', true);

        $this->getJson('/api/v1/restaurant/ping')
            ->assertForbidden()
            ->assertJsonPath('code', 'BRANCH_CONTEXT_REQUIRED');

        $this->withHeader('X-Restaurant-Id', $restaurant->public_id)
            ->getJson('/api/v1/restaurant/ping')
            ->assertOk()
            ->assertJsonPath('data.restaurant_id', $restaurant->id);
    }

    public function test_owner_cannot_access_other_restaurant_via_header(): void
    {
        $this->seedRoles();
        $a = $this->makeRestaurant('kitchen-a');
        $b = $this->makeRestaurant('kitchen-b');
        $owner = $this->restaurantOwner($a);

        // Link hierarchy so branch-aware middleware can resolve.
        app(\App\Services\Business\BusinessHierarchyMigrator::class)->migrateRestaurant($a);
        app(\App\Services\Business\BusinessHierarchyMigrator::class)->migrateRestaurant($b);

        Sanctum::actingAs($owner);

        $this->withHeader('X-Restaurant-Id', $b->fresh()->public_id)
            ->getJson('/api/v1/restaurant/ping')
            ->assertNotFound();

        $this->withHeader('X-Restaurant-Id', $a->fresh()->public_id)
            ->getJson('/api/v1/restaurant/ping')
            ->assertOk()
            ->assertJsonPath('data.restaurant_id', $a->id);
    }

    public function test_admin_can_revoke_owner_and_access_is_lost(): void
    {
        $admin = $this->superAdmin();
        $restaurant = $this->makeRestaurant();
        $owner = $this->restaurantOwner($restaurant);

        Sanctum::actingAs($admin);
        Session::put('mfa.verified', true);
        $this->deleteJson("/api/v1/admin/restaurants/{$restaurant->public_id}/owners/{$owner->id}")
            ->assertOk();

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/restaurant/ping')->assertForbidden();
    }

    public function test_restaurant_owner_can_invite_and_revoke_staff(): void
    {
        $this->seedRoles();
        $restaurant = $this->makeRestaurant();
        $owner = $this->restaurantOwner($restaurant);

        Sanctum::actingAs($owner);

        $invite = $this->postJson('/api/v1/restaurant/staff', [
            'first_name' => 'New',
            'last_name' => 'Staff',
            'email' => 'new-staff@example.com',
            'role' => 'restaurant_staff',
            'password' => 'Password1!',
        ])->assertCreated();

        $staffId = $invite->json('data.member.user_id');

        $this->getJson('/api/v1/restaurant/staff')
            ->assertOk()
            ->assertJsonFragment(['email' => 'new-staff@example.com']);

        $this->deleteJson("/api/v1/restaurant/staff/{$staffId}")->assertOk();

        $staff = User::query()->findOrFail($staffId);
        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/restaurant/ping')->assertForbidden();
    }

    private function seedRoles(): void
    {
        foreach (config('suvakamana.permissions') as $slug) {
            Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }

        foreach (config('suvakamana.roles') as $roleSlug) {
            Role::query()->firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug, 'guard' => 'web']);
        }

        $map = [
            'restaurant_owner' => [
                'view_restaurant_dashboard',
                'view_restaurant_profile',
                'manage_restaurant_staff',
                'view_menu',
                'manage_menu_items',
            ],
            'restaurant_manager' => [
                'view_restaurant_dashboard',
                'view_menu',
            ],
            'restaurant_staff' => [
                'view_restaurant_dashboard',
                'view_menu',
            ],
            'super_admin' => [
                'view_super_admin_dashboard',
                'manage_restaurants',
            ],
        ];

        foreach ($map as $roleSlug => $perms) {
            $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
            $role->permissions()->sync(
                Permission::query()->whereIn('slug', $perms)->pluck('id')
            );
        }
    }

    private function superAdmin(): User
    {
        $this->seedRoles();
        $user = User::factory()->create([
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $role = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $user->roles()->attach($role->id);
        $user->load('roles.permissions');

        return $user;
    }

    private function makeRestaurant(string $slug = 'test-kitchen'): Restaurant
    {
        return Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'trading_name' => Str::headline($slug),
            'legal_business_name' => Str::headline($slug).' Pty Ltd',
            'slug' => $slug,
            'status' => 'active',
            'ownership_type' => 'third_party',
        ]);
    }

    private function restaurantOwner(Restaurant $restaurant): User
    {
        $role = Role::query()->where('slug', 'restaurant_owner')->firstOrFail();
        $user = User::factory()->create([
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($role->id, ['restaurant_id' => $restaurant->id]);
        RestaurantUser::query()->create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $user->load('roles.permissions');

        return $user;
    }
}
