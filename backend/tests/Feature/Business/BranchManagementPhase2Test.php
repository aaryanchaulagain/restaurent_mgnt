<?php

namespace Tests\Feature\Business;

use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use App\Services\Business\BusinessHierarchyMigrator;
use App\Support\BranchStatuses;
use App\Support\BusinessRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchManagementPhase2Test extends TestCase
{
    use RefreshDatabase;

    public function test_business_owner_can_list_and_create_branch(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/businesses/{$business->public_id}/branches")
            ->assertOk()
            ->assertJsonPath('data.counts.total', 1);

        $response = $this->postJson("/api/v1/businesses/{$business->public_id}/branches", [
            'name' => 'Itahari Branch',
            'code' => 'ITAHARI',
            'address_line' => '1 Market Rd',
            'city' => 'Itahari',
            'status' => BranchStatuses::DRAFT,
        ])->assertCreated();

        $branchPublicId = $response->json('data.branch.public_id');
        $restaurantPublicId = $response->json('data.restaurant_public_id');

        $branch = Branch::query()->where('public_id', $branchPublicId)->firstOrFail();
        $this->assertSame($business->id, $branch->business_id);
        $this->assertNotNull($branch->restaurant_id);
        $this->assertSame($restaurantPublicId, $branch->restaurant->public_id);
        $this->assertSame($branch->id, $branch->restaurant->branch_id);
        $this->assertSame($business->id, $branch->restaurant->business_id);
        $this->assertSame(BranchStatuses::DRAFT, $branch->status);
    }

    public function test_owner_cannot_create_branch_under_another_business(): void
    {
        [$ownerA, $businessA] = $this->ownerWithBusiness('biz-a');
        [, $businessB] = $this->ownerWithBusiness('biz-b');

        Sanctum::actingAs($ownerA);
        $this->postJson("/api/v1/businesses/{$businessB->public_id}/branches", [
            'name' => 'Hacker Branch',
        ])->assertForbidden();
    }

    public function test_nested_route_rejects_branch_from_another_business(): void
    {
        [$ownerA, $businessA] = $this->ownerWithBusiness('nest-a');
        [, $businessB, $branchB] = $this->ownerWithBusiness('nest-b');

        Sanctum::actingAs($ownerA);
        $this->getJson("/api/v1/businesses/{$businessA->public_id}/branches/{$branchB->public_id}")
            ->assertNotFound();
    }

    public function test_branch_manager_cannot_view_other_branch_or_suspend(): void
    {
        [$owner, $business, $branchA] = $this->ownerWithBusiness('mgr-a');
        Sanctum::actingAs($owner);
        $created = $this->postJson("/api/v1/businesses/{$business->public_id}/branches", [
            'name' => 'Second',
            'code' => 'SECOND',
        ])->assertCreated();
        $branchB = Branch::query()->where('public_id', $created->json('data.branch.public_id'))->firstOrFail();

        $manager = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        app(\App\Services\Branch\BranchStaffService::class)->assign($branchA, [
            'email' => $manager->email,
            'role' => BusinessRoles::BRANCH_MANAGER,
        ], $owner);

        Sanctum::actingAs($manager->fresh());
        $this->getJson("/api/v1/businesses/{$business->public_id}/branches/{$branchA->public_id}")
            ->assertOk();
        $this->getJson("/api/v1/businesses/{$business->public_id}/branches/{$branchB->public_id}")
            ->assertForbidden();

        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);
        Session::put('mfa.verified', true);
        $this->postJson("/api/v1/admin/branches/{$branchA->public_id}/suspend")->assertOk();

        Sanctum::actingAs($owner->fresh());
        $this->postJson("/api/v1/businesses/{$business->public_id}/branches/{$branchA->public_id}/activate")
            ->assertStatus(422);
    }

    public function test_super_admin_can_suspend_and_unsuspend(): void
    {
        [, , $branch] = $this->ownerWithBusiness('susp');
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);
        Session::put('mfa.verified', true);

        $this->postJson("/api/v1/admin/branches/{$branch->public_id}/suspend")
            ->assertOk()
            ->assertJsonPath('data.branch.status', BranchStatuses::SUSPENDED);

        $this->postJson("/api/v1/admin/branches/{$branch->public_id}/unsuspend")
            ->assertOk()
            ->assertJsonPath('data.branch.status', BranchStatuses::PAUSED);
    }

    public function test_x_branch_id_resolves_restaurant_and_blocks_spoof(): void
    {
        [$owner, $business, $branch] = $this->ownerWithBusiness('ctx');
        [, , $otherBranch] = $this->ownerWithBusiness('ctx-other');

        Sanctum::actingAs($owner);
        $this->withHeader('X-Branch-Id', $branch->public_id)
            ->getJson('/api/v1/restaurant/ping')
            ->assertOk()
            ->assertJsonPath('data.branch_public_id', $branch->public_id)
            ->assertJsonPath('data.restaurant_public_id', $branch->restaurant->public_id)
            ->assertJsonPath('data.business_public_id', $business->public_id);

        $this->withHeader('X-Branch-Id', $otherBranch->public_id)
            ->getJson('/api/v1/restaurant/ping')
            ->assertNotFound();

        $this->withHeaders([
            'X-Branch-Id' => $branch->public_id,
            'X-Restaurant-Id' => $otherBranch->restaurant->public_id,
        ])->getJson('/api/v1/restaurant/ping')
            ->assertForbidden()
            ->assertJsonPath('code', 'BRANCH_RESTAURANT_MISMATCH');
    }

    public function test_legacy_x_restaurant_id_still_works_for_super_admin(): void
    {
        [, , $branch] = $this->ownerWithBusiness('legacy');
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);
        Session::put('mfa.verified', true);

        $this->withHeader('X-Restaurant-Id', $branch->restaurant->public_id)
            ->getJson('/api/v1/restaurant/ping')
            ->assertOk()
            ->assertJsonPath('data.branch_public_id', $branch->public_id);
    }

    public function test_context_endpoint_scopes_branches_for_manager(): void
    {
        [$owner, $business, $branchA] = $this->ownerWithBusiness('sw');
        Sanctum::actingAs($owner);
        $created = $this->postJson("/api/v1/businesses/{$business->public_id}/branches", [
            'name' => 'Other',
            'code' => 'OTHER',
        ])->assertCreated();
        $branchBId = $created->json('data.branch.public_id');

        $manager = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        app(\App\Services\Branch\BranchStaffService::class)->assign($branchA, [
            'email' => $manager->email,
            'role' => BusinessRoles::BRANCH_MANAGER,
        ], $owner);

        Sanctum::actingAs($owner->fresh());
        $ownerCtx = $this->getJson('/api/v1/businesses/context')->assertOk();
        $this->assertTrue($ownerCtx->json('data.can_aggregate'));
        $this->assertGreaterThanOrEqual(2, count($ownerCtx->json('data.branches')));

        Sanctum::actingAs($manager->fresh());
        $mgrCtx = $this->getJson('/api/v1/businesses/context')->assertOk();
        $this->assertFalse($mgrCtx->json('data.can_aggregate'));
        $ids = collect($mgrCtx->json('data.branches'))->pluck('public_id');
        $this->assertTrue($ids->contains($branchA->public_id));
        $this->assertFalse($ids->contains($branchBId));
    }

    public function test_branch_staff_syncs_legacy_restaurant_role(): void
    {
        [$owner, , $branch] = $this->ownerWithBusiness('sync');
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/businesses/{$branch->business->public_id}/branches/{$branch->public_id}/users", [
            'first_name' => 'Kit',
            'last_name' => 'Chen',
            'email' => 'kitchen@example.com',
            'role' => BusinessRoles::KITCHEN_STAFF,
            'password' => 'Password1!',
        ])->assertCreated();

        $userId = $response->json('data.user.id');
        $this->assertDatabaseHas('restaurant_users', [
            'restaurant_id' => $branch->restaurant_id,
            'user_id' => $userId,
            'status' => 'active',
        ]);

        $roleId = Role::query()->where('slug', 'restaurant_staff')->value('id');
        $this->assertDatabaseHas('restaurant_users', [
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);
    }

    public function test_cannot_remove_last_business_owner(): void
    {
        [$owner, $business] = $this->ownerWithBusiness('last-owner');
        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/businesses/{$business->public_id}/users/{$owner->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'LAST_BUSINESS_OWNER_REQUIRED');
    }

    /** @return array{0: User, 1: Business, 2: Branch} */
    private function ownerWithBusiness(string $slug = 'phase2-biz'): array
    {
        $this->seedRoles();
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'trading_name' => Str::headline($slug),
            'legal_business_name' => Str::headline($slug).' Pty Ltd',
            'slug' => $slug.'-'.Str::lower(Str::random(4)),
            'status' => 'active',
            'ownership_type' => 'third_party',
            'vendor_type' => 'restaurant',
            'accepting_orders' => true,
        ]);

        $owner = User::factory()->create([
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $role = Role::query()->where('slug', 'restaurant_owner')->firstOrFail();
        $owner->roles()->attach($role->id, ['restaurant_id' => $restaurant->id]);
        RestaurantUser::query()->create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $owner->id,
            'role_id' => $role->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $owner->load('roles.permissions');

        $result = app(BusinessHierarchyMigrator::class)->migrateRestaurant($restaurant->fresh());

        return [$owner->fresh(), $result['business'], $result['branch']->load('restaurant')];
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
            'restaurant_manager' => ['view_restaurant_dashboard', 'view_menu'],
            'restaurant_staff' => ['view_restaurant_dashboard', 'view_menu'],
            'super_admin' => ['view_super_admin_dashboard', 'manage_restaurants'],
        ];
        foreach ($map as $roleSlug => $perms) {
            $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
            $role->permissions()->sync(
                Permission::query()->whereIn('slug', $perms)->pluck('id')
            );
        }
    }
}
