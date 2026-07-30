<?php

namespace Tests\Feature\Auth;

use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_cannot_access_restaurant_or_admin_ping(): void
    {
        $this->seedMinimalRoles();
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('slug', 'customer')->first());

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/restaurant/ping')->assertForbidden();
        $this->getJson('/api/v1/admin/ping')->assertForbidden();
        $this->getJson('/api/v1/customer/ping')->assertOk();
    }

    public function test_restaurant_user_cannot_access_admin_ping(): void
    {
        $this->seedMinimalRoles();
        $ownerRole = Role::query()->where('slug', 'restaurant_owner')->first();
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'trading_name' => 'Test Kitchen',
            'legal_business_name' => 'Test Kitchen Pty Ltd',
            'slug' => 'test-kitchen',
            'status' => 'active',
        ]);
        $user->roles()->attach($ownerRole->id, ['restaurant_id' => $restaurant->id]);
        RestaurantUser::query()->create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $user->id,
            'role_id' => $ownerRole->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/ping')->assertForbidden();
        $this->getJson('/api/v1/restaurant/ping')
            ->assertOk()
            ->assertJsonPath('data.restaurant_id', $restaurant->id);
    }

    public function test_removed_staff_loses_access(): void
    {
        $this->seedMinimalRoles();
        $staffRole = Role::query()->where('slug', 'restaurant_staff')->first();
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'trading_name' => 'Test Kitchen',
            'legal_business_name' => 'Test Kitchen Pty Ltd',
            'slug' => 'test-kitchen',
            'status' => 'active',
        ]);
        $user->roles()->attach($staffRole->id, ['restaurant_id' => $restaurant->id]);
        RestaurantUser::query()->create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $user->id,
            'role_id' => $staffRole->id,
            'status' => 'removed',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/restaurant/ping')->assertForbidden();
    }

    private function seedMinimalRoles(): void
    {
        $map = [
            'customer' => ['view_customer_profile'],
            'restaurant_owner' => ['view_restaurant_dashboard'],
            'restaurant_staff' => ['view_restaurant_dashboard'],
            'super_admin' => ['view_super_admin_dashboard'],
        ];

        foreach ($map as $roleSlug => $perms) {
            $role = Role::query()->create(['slug' => $roleSlug, 'name' => $roleSlug, 'guard' => 'web']);
            foreach ($perms as $perm) {
                $permission = Permission::query()->firstOrCreate(
                    ['slug' => $perm],
                    ['name' => $perm],
                );
                $role->permissions()->attach($permission);
            }
        }
    }
}
