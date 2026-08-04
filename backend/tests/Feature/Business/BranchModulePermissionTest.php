<?php

namespace Tests\Feature\Business;

use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\Services\Branch\BranchStaffService;
use App\Services\Business\BusinessHierarchyMigrator;
use App\Support\BranchRolePermissionMatrix;
use App\Support\BusinessRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchModulePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::flush();
    }

    public function test_business_owner_can_access_all_own_branches_and_not_another_business(): void
    {
        [$owner, $business, $branchA] = $this->ownerWithBusiness('perm-own');
        $branchB = $this->extraBranch($business, 'Second');
        [, $otherBusiness, $otherBranch] = $this->ownerWithBusiness('perm-other');

        Sanctum::actingAs($owner);

        $this->withHeaders($this->tenantHeaders($branchA))
            ->getJson('/api/v1/restaurant/menu-items')
            ->assertOk();

        $this->withHeaders($this->tenantHeaders($branchB))
            ->getJson('/api/v1/restaurant/menu-items')
            ->assertOk();

        $this->withHeaders($this->tenantHeaders($otherBranch))
            ->getJson('/api/v1/restaurant/menu-items')
            ->assertForbidden()
            ->assertJsonPath('code', 'BRANCH_ACCESS_DENIED');

        $this->getJson("/api/v1/businesses/{$otherBusiness->public_id}/branches")
            ->assertForbidden();
    }

    public function test_branch_manager_scoped_permissions_and_header_bypass_rejected(): void
    {
        [$owner, $business, $branchA] = $this->ownerWithBusiness('mgr-scope');
        $branchB = $this->extraBranch($business, 'Unassigned');
        $manager = $this->assignExisting($branchA, BusinessRoles::BRANCH_MANAGER, $owner);

        Sanctum::actingAs($manager);

        $this->withHeaders($this->tenantHeaders($branchA))
            ->getJson('/api/v1/restaurant/authorization')
            ->assertOk()
            ->assertJsonPath('data.role', BusinessRoles::BRANCH_MANAGER);

        $perms = $this->withHeaders($this->tenantHeaders($branchA))
            ->getJson('/api/v1/restaurant/authorization')
            ->json('data.permissions');
        $this->assertContains('view_menu', $perms);
        $this->assertNotContains('manage_payment_accounts', $perms);

        $this->withHeaders($this->tenantHeaders($branchA))
            ->getJson('/api/v1/restaurant/menu-items')
            ->assertOk();

        $this->withHeaders($this->tenantHeaders($branchA))
            ->getJson('/api/v1/restaurant/inventory')
            ->assertOk();

        $this->withHeaders($this->tenantHeaders($branchA))
            ->getJson('/api/v1/restaurant/payment-account')
            ->assertForbidden()
            ->assertJsonPath('code', 'FINANCE_PERMISSION_DENIED');

        // Cannot access unassigned branch via X-Branch-Id.
        $this->flushHeaders();
        $this->withHeaders($this->tenantHeaders($branchB))
            ->getJson('/api/v1/restaurant/menu-items')
            ->assertForbidden()
            ->assertJsonPath('code', 'BRANCH_ACCESS_DENIED');

        // Mismatched restaurant header rejected.
        $this->flushHeaders();
        $this->withHeaders([
            'X-Branch-Id' => $branchA->public_id,
            'X-Restaurant-Id' => $branchB->restaurant->public_id,
        ])->getJson('/api/v1/restaurant/menu-items')
            ->assertForbidden()
            ->assertJsonPath('code', 'BRANCH_RESTAURANT_MISMATCH');

        // X-Restaurant-Id alone for unassigned branch denied.
        $this->flushHeaders();
        $this->withHeaders(['X-Restaurant-Id' => $branchB->restaurant->public_id])
            ->getJson('/api/v1/restaurant/menu-items')
            ->assertForbidden()
            ->assertJsonPath('code', 'BRANCH_ACCESS_DENIED');
    }

    public function test_order_manager_cannot_edit_inventory_or_catalogue(): void
    {
        [$owner, , $branch] = $this->ownerWithBusiness('ord-mgr');
        $user = $this->assignExisting($branch, BusinessRoles::ORDER_MANAGER, $owner);

        Sanctum::actingAs($user);
        $headers = $this->tenantHeaders($branch);

        $this->withHeaders($headers)->getJson('/api/v1/restaurant/orders')->assertOk();
        $this->withHeaders($headers)->getJson('/api/v1/restaurant/inventory')
            ->assertForbidden()
            ->assertJsonPath('code', 'INVENTORY_PERMISSION_DENIED');
        $this->withHeaders($headers)->postJson('/api/v1/restaurant/menu-categories', [
            'name' => 'Hacked',
        ])->assertForbidden()
            ->assertJsonPath('code', 'MODULE_PERMISSION_DENIED');
        $this->withHeaders($headers)->getJson('/api/v1/restaurant/payment-account')
            ->assertForbidden();
    }

    public function test_inventory_manager_cannot_accept_orders_or_manage_staff(): void
    {
        [$owner, , $branch] = $this->ownerWithBusiness('inv-mgr');
        $user = $this->assignExisting($branch, BusinessRoles::INVENTORY_MANAGER, $owner);

        Sanctum::actingAs($user);
        $headers = $this->tenantHeaders($branch);

        $this->withHeaders($headers)->getJson('/api/v1/restaurant/inventory')->assertOk();
        $this->withHeaders($headers)->postJson('/api/v1/restaurant/orders/fake/accept')
            ->assertForbidden()
            ->assertJsonPath('code', 'ORDER_PERMISSION_DENIED');
        $this->withHeaders($headers)->getJson('/api/v1/restaurant/staff')
            ->assertForbidden();
    }

    public function test_kitchen_staff_can_prepare_but_not_finance_or_staff(): void
    {
        [$owner, , $branch] = $this->ownerWithBusiness('kitchen');
        $user = $this->assignExisting($branch, BusinessRoles::KITCHEN_STAFF, $owner);

        Sanctum::actingAs($user);
        $headers = $this->tenantHeaders($branch);

        $auth = $this->withHeaders($headers)->getJson('/api/v1/restaurant/authorization')->assertOk();
        $perms = $auth->json('data.permissions');
        $this->assertContains('prepare_restaurant_orders', $perms);
        $this->assertNotContains('reject_restaurant_orders', $perms);
        $this->assertNotContains('view_finance', $perms);
        $this->assertNotContains('manage_restaurant_staff', $perms);

        $this->withHeaders($headers)->getJson('/api/v1/restaurant/orders')->assertOk();
        $this->withHeaders($headers)->getJson('/api/v1/restaurant/payment-account')->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/v1/restaurant/staff')->assertForbidden();
    }

    public function test_delivery_staff_limited_permissions(): void
    {
        [$owner, , $branch] = $this->ownerWithBusiness('delivery');
        $user = $this->assignExisting($branch, BusinessRoles::DELIVERY_STAFF, $owner);

        Sanctum::actingAs($user);
        $headers = $this->tenantHeaders($branch);

        $this->withHeaders($headers)->getJson('/api/v1/restaurant/orders')->assertOk();
        $this->withHeaders($headers)->getJson('/api/v1/restaurant/menu-items')->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/v1/restaurant/inventory')->assertForbidden();
        $this->withHeaders($headers)->getJson('/api/v1/restaurant/payment-account')->assertForbidden();
    }

    public function test_customer_cannot_access_partner_apis(): void
    {
        $this->seedMinimalRoles();
        $customerRole = Role::query()->firstOrCreate(
            ['slug' => 'customer'],
            ['name' => 'Customer', 'guard' => 'web'],
        );
        $customer = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $customer->roles()->attach($customerRole->id, ['restaurant_id' => null]);

        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/businesses/context')->assertForbidden();
        $this->getJson('/api/v1/restaurant/ping')->assertForbidden();
    }

    public function test_branch_manager_cannot_assign_business_owner_or_elevate(): void
    {
        [$owner, $business, $branch] = $this->ownerWithBusiness('elevate');
        $manager = $this->assignExisting($branch, BusinessRoles::BRANCH_MANAGER, $owner);
        $peer = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);

        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/businesses/{$business->public_id}/branches/{$branch->public_id}/users", [
            'email' => $peer->email,
            'role' => BusinessRoles::BRANCH_MANAGER,
        ])->assertStatus(422);

        $this->postJson("/api/v1/businesses/{$business->public_id}/users", [
            'email' => $peer->email,
            'role' => BusinessRoles::BUSINESS_OWNER,
        ])->assertForbidden();
    }

    public function test_partner_cannot_create_staff_with_temporary_password(): void
    {
        [$owner, $business, $branch] = $this->ownerWithBusiness('no-temp');

        Sanctum::actingAs($owner);
        $response = $this->postJson("/api/v1/businesses/{$business->public_id}/branches/{$branch->public_id}/users", [
            'first_name' => 'New',
            'last_name' => 'Hire',
            'email' => 'brand-new-hire@example.com',
            'role' => BusinessRoles::KITCHEN_STAFF,
            'password' => 'CallerChosen1!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'BRANCH_INVITATION_REQUIRED');
        $this->assertNull($response->json('data.temporary_password') ?? null);
        $this->assertFalse(str_contains(json_encode($response->json()), 'CallerChosen1!'));
        $this->assertDatabaseMissing('users', ['email' => 'brand-new-hire@example.com']);
    }

    public function test_existing_user_direct_assignment_still_works_without_password(): void
    {
        [$owner, $business, $branch] = $this->ownerWithBusiness('exist-assign');
        $existing = User::factory()->create([
            'email' => 'existing.staff@example.com',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($owner);
        $response = $this->postJson("/api/v1/businesses/{$business->public_id}/branches/{$branch->public_id}/users", [
            'email' => $existing->email,
            'role' => BusinessRoles::ORDER_MANAGER,
        ])->assertCreated();

        $this->assertArrayNotHasKey('temporary_password', $response->json('data') ?? []);
        $this->assertDatabaseHas('branch_users', [
            'branch_id' => $branch->id,
            'user_id' => $existing->id,
            'role' => BusinessRoles::ORDER_MANAGER,
            'status' => 'active',
        ]);
    }

    public function test_permission_matrix_templates_are_backend_controlled(): void
    {
        $this->assertContains('manage_payment_accounts', BranchRolePermissionMatrix::businessOwner());
        $this->assertNotContains('manage_payment_accounts', BranchRolePermissionMatrix::branchManager());
        $this->assertNotContains('manage_inventory', BranchRolePermissionMatrix::orderManager());
        $this->assertNotContains('accept_restaurant_orders', BranchRolePermissionMatrix::inventoryManager());
        $this->assertNotContains('reject_restaurant_orders', BranchRolePermissionMatrix::kitchenStaff());
    }

    public function test_context_returns_authorization_for_selected_branch(): void
    {
        [$owner, , $branch] = $this->ownerWithBusiness('ctx-authz');
        Sanctum::actingAs($owner);

        $this->withHeaders(['X-Branch-Id' => $branch->public_id])
            ->getJson('/api/v1/businesses/context')
            ->assertOk()
            ->assertJsonPath('data.authorization.branch.public_id', $branch->public_id)
            ->assertJsonPath('data.authorization.role', BusinessRoles::BUSINESS_OWNER);
    }

    /**
     * @return array{0: User, 1: Business, 2: Branch}
     */
    private function ownerWithBusiness(string $slug): array
    {
        $this->seedMinimalRoles();
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'trading_name' => \Illuminate\Support\Str::headline($slug),
            'legal_business_name' => \Illuminate\Support\Str::headline($slug).' Pty Ltd',
            'slug' => $slug.'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4)),
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
        \App\Models\RestaurantUser::query()->create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $owner->id,
            'role_id' => $role->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $owner->load('roles.permissions');

        $result = app(BusinessHierarchyMigrator::class)->migrateRestaurant($restaurant->fresh());

        return [$owner->fresh(), $result['business'], $result['branch']->load(['restaurant', 'business'])];
    }

    private function extraBranch(Business $business, string $name): Branch
    {
        $owner = BusinessUser::query()
            ->where('business_id', $business->id)
            ->where('role', BusinessRoles::BUSINESS_OWNER)
            ->where('status', 'active')
            ->firstOrFail()
            ->user;

        Sanctum::actingAs($owner);
        $response = $this->postJson("/api/v1/businesses/{$business->public_id}/branches", [
            'name' => $name,
            'code' => strtoupper(substr(preg_replace('/\s+/', '', $name), 0, 6)).random_int(10, 99),
            'status' => 'active',
        ])->assertCreated();

        return Branch::query()
            ->where('public_id', $response->json('data.branch.public_id'))
            ->firstOrFail()
            ->load(['restaurant', 'business']);
    }

    private function assignExisting(Branch $branch, string $role, User $actor): User
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        app(BranchStaffService::class)->assign($branch, [
            'email' => $user->email,
            'role' => $role,
        ], $actor);

        return $user->fresh(['roles.permissions']);
    }

    /** @return array<string, string> */
    private function tenantHeaders(Branch $branch): array
    {
        $branch->loadMissing('restaurant');

        return [
            'X-Branch-Id' => $branch->public_id,
            'X-Restaurant-Id' => $branch->restaurant->public_id,
            'X-Business-Id' => $branch->business->public_id ?? $branch->business()->value('public_id'),
        ];
    }

    private function seedMinimalRoles(): void
    {
        foreach (config('suvakamana.permissions') as $slug) {
            Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }
        foreach (config('suvakamana.roles') as $roleSlug) {
            Role::query()->firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug, 'guard' => 'web']);
        }

        $map = [
            'restaurant_owner' => config('suvakamana.permissions'),
            'restaurant_manager' => [
                'view_restaurant_dashboard',
                'view_restaurant_profile',
                'manage_restaurant_profile',
                'manage_restaurant_hours',
                'manage_restaurant_staff',
                'view_menu',
                'manage_menu_categories',
                'manage_menu_items',
                'manage_menu_availability',
                'view_inventory',
                'manage_inventory',
                'view_orders',
                'view_restaurant_orders',
                'accept_restaurant_orders',
                'reject_restaurant_orders',
                'prepare_restaurant_orders',
                'complete_restaurant_orders',
                'view_restaurant_payment_summaries',
            ],
            'restaurant_staff' => [
                'view_restaurant_dashboard',
                'view_menu',
                'view_orders',
                'view_restaurant_orders',
                'prepare_restaurant_orders',
                'view_inventory',
                'manage_inventory',
            ],
            'super_admin' => config('suvakamana.permissions'),
            'customer' => ['view_own_orders'],
        ];

        foreach ($map as $roleSlug => $perms) {
            $role = Role::query()->where('slug', $roleSlug)->first();
            if (! $role) {
                continue;
            }
            $role->permissions()->sync(
                Permission::query()->whereIn('slug', $perms)->pluck('id')
            );
        }
    }
}
