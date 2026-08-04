<?php

namespace Tests\Feature\Reporting;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Branch;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemInventory;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use App\Services\Business\BusinessHierarchyMigrator;
use App\Support\BusinessRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedRoles();
    }

    public function test_owner_can_view_business_summary_and_compare_branches(): void
    {
        [$owner, $business, $branchA] = $this->ownerWithBusiness('rep-own');
        $branchB = $this->extraBranch($business, 'Second');
        $this->seedOrder($branchA->restaurant, 2500, 'completed_pickup');
        $this->seedOrder($branchB->restaurant, 1500, 'awaiting_restaurant');

        Sanctum::actingAs($owner);
        $res = $this->getJson("/api/v1/businesses/{$business->public_id}/reports/summary?range=last_30_days")
            ->assertOk();

        $this->assertSame(2, $res->json('data.summary.total_orders'));
        $this->assertSame(1, $res->json('data.summary.completed_orders'));
        $this->assertSame(4000, $res->json('data.summary.gross_order_value_cents'));
        $this->assertNotEmpty($res->json('data.meta.timezone'));
        $this->assertCount(2, $res->json('data.branches'));
        $this->assertArrayNotHasKey('customer_email', $res->json('data.summary'));
        $this->assertArrayNotHasKey('id', $res->json('data.business'));
    }

    public function test_another_business_is_excluded(): void
    {
        [$owner, $business] = $this->ownerWithBusiness('rep-a');
        [, $other] = $this->ownerWithBusiness('rep-b');

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/businesses/{$other->public_id}/reports/summary")
            ->assertForbidden();
        $this->getJson("/api/v1/businesses/{$business->public_id}/reports/summary")
            ->assertOk();
    }

    public function test_branch_manager_cannot_view_business_aggregate(): void
    {
        [$owner, $business, $branchA] = $this->ownerWithBusiness('mgr-agg');
        $branchB = $this->extraBranch($business, 'Sibling');
        $manager = $this->assignBranchManager($branchA, $owner);

        Sanctum::actingAs($manager);
        $this->getJson("/api/v1/businesses/{$business->public_id}/reports/summary")
            ->assertForbidden();

        $this->getJson("/api/v1/businesses/{$business->public_id}/branches/{$branchA->public_id}/reports/summary")
            ->assertOk();

        $this->getJson("/api/v1/businesses/{$business->public_id}/branches/{$branchB->public_id}/reports/summary")
            ->assertForbidden();
    }

    public function test_invalid_and_excessive_ranges_rejected(): void
    {
        [$owner, $business] = $this->ownerWithBusiness('range-biz');
        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/businesses/{$business->public_id}/reports/summary?range=forever")
            ->assertStatus(422)
            ->assertJsonPath('code', 'REPORT_DATE_RANGE_INVALID');

        $this->getJson("/api/v1/businesses/{$business->public_id}/reports/summary?range=custom&start=2020-01-01&end=2022-01-02")
            ->assertStatus(422)
            ->assertJsonPath('code', 'REPORT_DATE_RANGE_TOO_LARGE');
    }

    public function test_menu_price_change_does_not_alter_historical_gross(): void
    {
        [$owner, $business, $branch] = $this->ownerWithBusiness('snap-biz');
        $this->seedOrder($branch->restaurant, 9999, 'completed_pickup');
        Sanctum::actingAs($owner);

        $before = $this->getJson("/api/v1/businesses/{$business->public_id}/reports/summary")
            ->assertOk()
            ->json('data.summary.gross_order_value_cents');

        $branch->restaurant->update(['minimum_order_cents' => 1]);

        $after = $this->getJson("/api/v1/businesses/{$business->public_id}/reports/summary")
            ->assertOk()
            ->json('data.summary.gross_order_value_cents');

        $this->assertSame(9999, $before);
        $this->assertSame(9999, $after);
    }

    public function test_inventory_counts_are_branch_specific(): void
    {
        [$owner, $business, $branchA] = $this->ownerWithBusiness('inv-rep');
        $branchB = $this->extraBranch($business, 'Inv B');
        $this->lowStock($branchA->restaurant, 1);
        $this->lowStock($branchB->restaurant, 0);

        Sanctum::actingAs($owner);
        $a = $this->getJson("/api/v1/businesses/{$business->public_id}/branches/{$branchA->public_id}/reports/inventory")
            ->assertOk()
            ->json('data');
        $b = $this->getJson("/api/v1/businesses/{$business->public_id}/branches/{$branchB->public_id}/reports/inventory")
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $a['low_stock_count']);
        $this->assertSame(0, $a['out_of_stock_count']);
        $this->assertSame(0, $b['low_stock_count']);
        $this->assertSame(1, $b['out_of_stock_count']);
    }

    public function test_super_admin_can_inspect_without_partner_password(): void
    {
        [, $business, $branch] = $this->ownerWithBusiness('admin-rep');
        $this->seedOrder($branch->restaurant, 1200, 'completed_pickup');
        $admin = $this->superAdmin();

        Sanctum::actingAs($admin);
        Session::put('mfa.verified', true);
        $this->getJson("/api/v1/admin/businesses/{$business->public_id}/reports/summary")
            ->assertOk()
            ->assertJsonPath('data.viewer', 'super_admin')
            ->assertJsonPath('data.summary.total_orders', 1);

        $this->getJson("/api/v1/admin/businesses/{$business->public_id}/branches/{$branch->public_id}/reports/summary")
            ->assertOk()
            ->assertJsonPath('data.viewer', 'super_admin')
            ->assertJsonPath('data.configuration.linked_restaurant_slug', $branch->restaurant->slug);
    }

    public function test_customer_cannot_access_reports(): void
    {
        [, $business] = $this->ownerWithBusiness('cust-rep');
        $customer = User::factory()->create(['email_verified_at' => now(), 'status' => 'active']);
        $customer->roles()->attach(Role::query()->where('slug', 'customer')->value('id'));
        Sanctum::actingAs($customer);

        $this->getJson("/api/v1/businesses/{$business->public_id}/reports/summary")
            ->assertForbidden();
    }

    public function test_nested_branch_from_other_business_returns_404(): void
    {
        [$owner, $business] = $this->ownerWithBusiness('nest-a');
        [, , $foreign] = $this->ownerWithBusiness('nest-b');
        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/businesses/{$business->public_id}/branches/{$foreign->public_id}/reports/summary")
            ->assertNotFound();
    }

    private function seedRoles(): void
    {
        foreach (config('suvakamana.permissions') as $slug) {
            Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }
        foreach (config('suvakamana.roles') as $roleSlug) {
            Role::query()->firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug, 'guard' => 'web']);
        }
        foreach ([
            BusinessRoles::BUSINESS_OWNER,
            BusinessRoles::BRANCH_MANAGER,
            BusinessRoles::ACCOUNTANT,
        ] as $slug) {
            Role::query()->firstOrCreate(['slug' => $slug], ['name' => $slug, 'guard' => 'web']);
        }
        $owner = Role::query()->where('slug', 'restaurant_owner')->first();
        if ($owner) {
            $owner->permissions()->syncWithoutDetaching(
                Permission::query()->whereIn('slug', config('suvakamana.permissions'))->pluck('id')
            );
        }
        Role::query()->firstOrCreate(['slug' => 'customer'], ['name' => 'Customer', 'guard' => 'web']);
        Role::query()->firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin', 'guard' => 'web']);
        $sa = Role::query()->where('slug', 'super_admin')->first();
        $sa?->permissions()->syncWithoutDetaching(Permission::query()->pluck('id'));
        $mgr = Role::query()->where('slug', 'restaurant_manager')->firstOrCreate(
            ['slug' => 'restaurant_manager'],
            ['name' => 'restaurant_manager', 'guard' => 'web']
        );
        $mgr->permissions()->syncWithoutDetaching(
            Permission::query()->whereIn('slug', [
                'view_restaurant_dashboard',
                'view_restaurant_orders',
                'view_inventory',
                'view_restaurant_payment_summaries',
            ])->pluck('id')
        );
    }

    /** @return array{0: User, 1: Business, 2: Branch} */
    private function ownerWithBusiness(string $slug): array
    {
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'trading_name' => Str::headline($slug),
            'legal_business_name' => Str::headline($slug).' Pty Ltd',
            'slug' => $slug.'-'.Str::lower(Str::random(4)),
            'status' => RestaurantStatus::Active,
            'ownership_type' => 'third_party',
            'vendor_type' => 'grocery',
            'accepting_orders' => true,
            'published_at' => now(),
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
        ]);

        $owner = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
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

    private function assignBranchManager(Branch $branch, User $actor): User
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        app(\App\Services\Branch\BranchStaffService::class)->assign($branch, [
            'email' => $user->email,
            'role' => BusinessRoles::BRANCH_MANAGER,
        ], $actor);

        return $user->fresh(['roles.permissions']);
    }

    private function seedOrder(Restaurant $restaurant, int $totalCents, string $status): Order
    {
        return Order::query()->create([
            'public_id' => (string) Str::uuid(),
            'order_number' => 'SVK-REP-'.Str::upper(Str::random(6)),
            'idempotency_key' => 'rep-'.Str::lower(Str::random(8)),
            'restaurant_id' => $restaurant->id,
            'status' => $status,
            'payment_method' => 'cash',
            'payment_status' => $status === 'completed_pickup' ? 'unpaid' : 'unpaid',
            'fulfilment_type' => 'pickup',
            'currency' => 'AUD',
            'subtotal_cents' => $totalCents,
            'discount_cents' => 0,
            'tax_cents' => 0,
            'service_fee_cents' => 0,
            'delivery_fee_cents' => 0,
            'total_cents' => $totalCents,
            'commission_rate_snapshot' => 0,
            'commission_amount_cents' => 0,
            'restaurant_net_estimate_cents' => $totalCents,
            'placed_at' => now()->subDay(),
            'completed_at' => $status === 'completed_pickup' ? now()->subHours(12) : null,
            'customer_name_snapshot' => 'Report Customer',
            'customer_email_snapshot' => 'secret@example.test',
        ]);
    }

    private function lowStock(Restaurant $restaurant, int $qty): void
    {
        $menu = Menu::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Report Menu',
            'is_active' => true,
        ]);
        $category = MenuCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'name' => 'Report Cat',
            'is_active' => true,
        ]);
        $item = MenuItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'menu_category_id' => $category->id,
            'name' => 'Report Item '.$qty,
            'slug' => 'rep-item-'.Str::lower(Str::random(6)),
            'base_price_cents' => 100,
            'is_active' => true,
            'is_available' => true,
        ]);
        MenuItemInventory::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_item_id' => $item->id,
            'menu_item_variant_id' => null,
            'variant_scope' => 0,
            'track_stock' => true,
            'quantity_on_hand' => $qty,
            'low_stock_threshold' => 5,
            'force_unavailable' => false,
        ]);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $user->roles()->attach($role->id);

        return $user->fresh(['roles.permissions']);
    }
}
