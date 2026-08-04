<?php

namespace Tests\Feature\Order;

use App\Models\Branch;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use App\Services\Branch\BranchStaffService;
use App\Services\Business\BusinessHierarchyMigrator;
use App\Support\BusinessRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 4: orders route only to the cart's restaurant/branch board.
 */
class OrderBranchRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::flush();
        $this->seedMinimalRoles();
    }

    private function seedMinimalRoles(): void
    {
        foreach (config('suvakamana.permissions') as $slug) {
            Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }
        foreach (config('suvakamana.roles') as $roleSlug) {
            Role::query()->firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug, 'guard' => 'web']);
        }
        foreach ([
            BusinessRoles::BRANCH_MANAGER,
            BusinessRoles::BUSINESS_OWNER,
            BusinessRoles::ORDER_MANAGER,
        ] as $slug) {
            Role::query()->firstOrCreate(['slug' => $slug], ['name' => $slug, 'guard' => 'web']);
        }

        $map = [
            'restaurant_owner' => config('suvakamana.permissions'),
            'restaurant_manager' => [
                'view_restaurant_dashboard',
                'view_restaurant_orders',
                'accept_restaurant_orders',
                'reject_restaurant_orders',
                'prepare_restaurant_orders',
                'complete_restaurant_orders',
                'view_menu',
                'view_inventory',
            ],
            'restaurant_staff' => [
                'view_restaurant_dashboard',
                'view_restaurant_orders',
                'prepare_restaurant_orders',
                'view_menu',
                'view_inventory',
            ],
            'customer' => ['view_own_orders'],
        ];

        foreach ($map as $roleSlug => $perms) {
            $role = Role::query()->where('slug', $roleSlug)->first();
            if (! $role) {
                continue;
            }
            $ids = Permission::query()->whereIn('slug', $perms)->pluck('id');
            $role->permissions()->syncWithoutDetaching($ids);
        }
    }

    public function test_sibling_branch_manager_cannot_see_order(): void
    {
        [$owner, $business, $branchA] = $this->ownerWithBusiness('route-a');
        $branchB = $this->extraBranch($business, 'Sibling');

        $order = Order::query()->create([
            'public_id' => (string) Str::uuid(),
            'order_number' => 'SVK-BRANCH-'.Str::upper(Str::random(6)),
            'idempotency_key' => 'idem-branch-route-1',
            'restaurant_id' => $branchA->restaurant_id,
            'status' => 'awaiting_restaurant',
            'payment_method' => 'cash',
            'payment_status' => 'unpaid',
            'fulfilment_type' => 'pickup',
            'currency' => 'AUD',
            'subtotal_cents' => 1000,
            'discount_cents' => 0,
            'tax_cents' => 0,
            'service_fee_cents' => 0,
            'delivery_fee_cents' => 0,
            'total_cents' => 1000,
            'commission_rate_snapshot' => 0,
            'commission_amount_cents' => 0,
            'restaurant_net_estimate_cents' => 1000,
            'placed_at' => now(),
            'customer_name_snapshot' => 'Test Customer',
            'customer_email_snapshot' => 'c@example.test',
        ]);

        $managerA = $this->assignExisting($branchA, BusinessRoles::BRANCH_MANAGER, $owner);
        $managerB = $this->assignExisting($branchB, BusinessRoles::BRANCH_MANAGER, $owner);

        Sanctum::actingAs($managerA);
        $this->withHeaders(['X-Branch-Id' => $branchA->public_id])
            ->getJson('/api/v1/restaurant/orders')
            ->assertOk()
            ->assertJsonFragment(['public_id' => $order->public_id]);

        Sanctum::actingAs($managerB);
        $this->withHeaders(['X-Branch-Id' => $branchB->public_id])
            ->getJson('/api/v1/restaurant/orders')
            ->assertOk()
            ->assertJsonMissing(['public_id' => $order->public_id]);

        $this->withHeaders(['X-Branch-Id' => $branchB->public_id])
            ->getJson("/api/v1/restaurant/orders/{$order->public_id}")
            ->assertNotFound();
    }

    public function test_client_cannot_override_order_restaurant_via_payload(): void
    {
        $src = file_get_contents(app_path('Services/Order/OrderPlacementService.php'));
        $this->assertStringNotContainsString("\$input['restaurant_id']", $src);
        $this->assertStringNotContainsString("\$input['branch_id']", $src);
        $this->assertStringContainsString('CartBranchContext', $src);
    }

    /** @return array{0: User, 1: Business, 2: Branch} */
    private function ownerWithBusiness(string $slug): array
    {
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'trading_name' => Str::headline($slug),
            'legal_business_name' => Str::headline($slug).' Pty Ltd',
            'slug' => $slug.'-'.Str::lower(Str::random(4)),
            'status' => 'active',
            'ownership_type' => 'third_party',
            'vendor_type' => 'restaurant',
            'accepting_orders' => true,
            'published_at' => now(),
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
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
}
