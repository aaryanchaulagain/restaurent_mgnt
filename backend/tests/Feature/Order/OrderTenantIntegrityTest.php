<?php

namespace Tests\Feature\Order;

use App\Enums\Partner\RestaurantStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\Services\Business\BusinessHierarchyMigrator;
use App\Services\Order\OrderTenantIntegrityService;
use App\Support\BusinessRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderTenantIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedRoles();
    }

    public function test_valid_order_is_not_reported(): void
    {
        [, , $branch] = $this->ownerWithBusiness('ok-biz');
        $order = $this->makeOrder($branch->restaurant, 'SVK-OK-1');

        $row = app(OrderTenantIntegrityService::class)->classifyOrder($order);
        $this->assertSame(OrderTenantIntegrityService::CLASS_OK, $row['classification']);
    }

    public function test_soft_deleted_restaurant_order_classified(): void
    {
        [, , $branch] = $this->ownerWithBusiness('soft-biz');
        $restaurant = $branch->restaurant;
        $order = $this->makeOrder($restaurant, 'SVK-SOFT-1', total: 2500, paymentStatus: 'paid');
        $restaurant->delete();

        $row = app(OrderTenantIntegrityService::class)->classifyOrder($order->fresh());
        $this->assertSame(OrderTenantIntegrityService::CLASS_SOFT_DELETED, $row['classification']);
        $this->assertFalse($row['repair_safe']);
        $this->assertTrue($row['restaurant_soft_deleted']);
        $this->assertSame(2500, $row['total_cents']);
    }

    public function test_physically_missing_restaurant_detected(): void
    {
        [, , $branch] = $this->ownerWithBusiness('miss-biz');
        $order = $this->makeOrder($branch->restaurant, 'SVK-MISS-1');
        $order->forceFill(['restaurant_id' => 999999])->save();

        $row = app(OrderTenantIntegrityService::class)->classifyOrder($order->fresh());
        $this->assertSame(OrderTenantIntegrityService::CLASS_RESTAURANT_MISSING, $row['classification']);
        $this->assertFalse($row['repair_safe']);
        $this->assertSame('PRESERVE_FOR_MANUAL_REVIEW', $row['proposed_action']);
    }

    public function test_mutual_link_mismatch_not_auto_repaired_across_business(): void
    {
        [, $businessA, $branchA] = $this->ownerWithBusiness('mm-a');
        $restaurant = $branchA->restaurant;
        $restaurant->forceFill([
            'branch_id' => null,
            'business_id' => $businessA->id,
        ])->save();
        $branchA->forceFill(['restaurant_id' => null])->save();
        $order = $this->makeOrder($restaurant->fresh(), 'SVK-MM-1');

        $row = app(OrderTenantIntegrityService::class)->classifyOrder($order);
        $this->assertFalse($row['repair_safe']);
        $this->assertSame(OrderTenantIntegrityService::CLASS_BRANCH_MISSING, $row['classification']);
        $this->assertContains($row['proposed_action'], [
            'PRESERVE_FOR_MANUAL_REVIEW',
            'CLASSIFY_LEGACY_OR_MANUAL_REVIEW',
        ]);
    }

    public function test_unique_inverse_branch_link_can_be_repaired_idempotently(): void
    {
        [, , $branch] = $this->ownerWithBusiness('fix-biz');
        $restaurant = $branch->restaurant;
        $restaurant->forceFill(['branch_id' => null])->save();
        $order = $this->makeOrder($restaurant->fresh(), 'SVK-FIX-1', total: 4400);
        Payment::query()->create([
            'public_id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'restaurant_id' => $restaurant->id,
            'status' => 'paid',
            'amount_cents' => 4400,
            'amount_refunded_cents' => 0,
            'currency' => 'AUD',
        ]);

        $service = app(OrderTenantIntegrityService::class);
        $classified = $service->classifyOrder($order->fresh());
        $this->assertTrue($classified['repair_safe']);
        $this->assertSame('SET_RESTAURANT_BRANCH_ID_FROM_UNIQUE_INVERSE', $classified['proposed_action']);

        $beforeTotal = $order->total_cents;
        $beforePay = $order->payment_status;
        $result = $service->repairConfirmed($order->public_id);
        $this->assertTrue($result['ok']);
        $this->assertSame($branch->id, $restaurant->fresh()->branch_id);
        $this->assertSame($beforeTotal, $order->fresh()->total_cents);
        $this->assertSame($beforePay, $order->fresh()->payment_status);
        $this->assertSame(4400, (int) Payment::query()->where('order_id', $order->id)->value('amount_cents'));

        $again = $service->repairConfirmed($order->public_id);
        $this->assertFalse($again['ok']);

        $this->assertTrue(
            AuditLog::query()->where('action', 'order.tenant_integrity.repaired')->exists()
        );
    }

    public function test_read_only_command_changes_nothing(): void
    {
        [, , $branch] = $this->ownerWithBusiness('ro-biz');
        $restaurant = $branch->restaurant;
        $order = $this->makeOrder($restaurant, 'SVK-RO-1', total: 1200);
        $restaurant->delete();

        $before = [
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'total_cents' => $order->total_cents,
            'deleted' => $restaurant->fresh()->trashed(),
        ];

        $this->artisan('orders:tenant-integrity')->assertSuccessful();

        $order->refresh();
        $this->assertSame($before['status'], $order->status);
        $this->assertSame($before['payment_status'], $order->payment_status);
        $this->assertSame($before['total_cents'], $order->total_cents);
        $this->assertTrue($restaurant->fresh()->trashed());
    }

    public function test_repair_requires_matching_confirm(): void
    {
        $this->artisan('orders:tenant-integrity', [
            '--repair' => true,
            '--order' => 'abc',
            '--confirm' => 'xyz',
        ])->assertFailed();
    }

    public function test_demo_seed_markers_identified_deterministically(): void
    {
        [, , $branch] = $this->ownerWithBusiness('demo-biz');
        $restaurant = $branch->restaurant;
        $restaurant->forceFill(['slug' => 'golden-wok'])->save();
        $order = $this->makeOrder($restaurant, 'PAY-SEED-00999');
        $order->forceFill([
            'public_id' => '550e8400-e29b-41d4-a716-0000000003e7',
            'idempotency_key' => 'seed-PAY-SEED-00999',
        ])->save();
        $restaurant->delete();

        $row = app(OrderTenantIntegrityService::class)->classifyOrder($order->fresh());
        $this->assertTrue($row['is_demo_or_seed']);
        $this->assertSame(OrderTenantIntegrityService::CLASS_SOFT_DELETED, $row['classification']);
    }

    public function test_admin_order_show_resolves_soft_deleted_restaurant(): void
    {
        [, , $branch] = $this->ownerWithBusiness('admin-soft');
        $restaurant = $branch->restaurant;
        $order = $this->makeOrder($restaurant, 'SVK-ADM-1');
        $restaurant->delete();

        $admin = $this->superAdmin();
        Sanctum::actingAs($admin);
        Session::put('mfa.verified', true);

        $this->getJson("/api/v1/admin/orders/{$order->public_id}")
            ->assertOk()
            ->assertJsonPath('data.order.restaurant.public_id', $restaurant->public_id)
            ->assertJsonPath('data.order.restaurant.soft_deleted', true)
            ->assertJsonPath('data.order.relationship.state', 'historical_soft_deleted');
    }

    public function test_soft_deleted_restaurant_absent_from_public_list(): void
    {
        [, , $branch] = $this->ownerWithBusiness('pub-soft');
        $slug = $branch->restaurant->slug;
        $branch->restaurant->forceFill([
            'status' => RestaurantStatus::Active,
            'published_at' => now(),
            'accepting_orders' => true,
        ])->save();
        $branch->restaurant->delete();

        $this->getJson("/api/v1/public/restaurants/{$slug}")
            ->assertNotFound();
    }

    public function test_ambiguous_name_similarity_is_never_used_for_repair(): void
    {
        [, , $branch] = $this->ownerWithBusiness('name-a');
        $this->extraNamedRestaurant($branch->business, 'Almost Same Kitchen');
        $order = $this->makeOrder($branch->restaurant, 'SVK-NAME-1');
        $order->forceFill(['restaurant_id' => 888888])->save();

        $row = app(OrderTenantIntegrityService::class)->classifyOrder($order->fresh());
        $this->assertSame(OrderTenantIntegrityService::CLASS_RESTAURANT_MISSING, $row['classification']);
        $this->assertFalse($row['repair_safe']);
    }

    private function seedRoles(): void
    {
        foreach (config('suvakamana.permissions') as $slug) {
            Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }
        foreach (config('suvakamana.roles') as $roleSlug) {
            Role::query()->firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug, 'guard' => 'web']);
        }
        foreach ([BusinessRoles::BUSINESS_OWNER, BusinessRoles::BRANCH_MANAGER] as $slug) {
            Role::query()->firstOrCreate(['slug' => $slug], ['name' => $slug, 'guard' => 'web']);
        }
        $owner = Role::query()->where('slug', 'restaurant_owner')->first();
        $owner?->permissions()->syncWithoutDetaching(Permission::query()->pluck('id'));
        Role::query()->firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin', 'guard' => 'web']);
        Role::query()->where('slug', 'super_admin')->first()?->permissions()->syncWithoutDetaching(Permission::query()->pluck('id'));
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
            'vendor_type' => 'restaurant',
            'accepting_orders' => true,
            'published_at' => now(),
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
        ]);

        $owner = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'restaurant_owner')->firstOrFail();
        $owner->roles()->attach($role->id, ['restaurant_id' => $restaurant->id]);
        \App\Models\RestaurantUser::query()->create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $owner->id,
            'role_id' => $role->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $result = app(BusinessHierarchyMigrator::class)->migrateRestaurant($restaurant->fresh());

        return [$owner->fresh(), $result['business'], $result['branch']->load(['restaurant', 'business'])];
    }

    private function makeOrder(Restaurant $restaurant, string $number, int $total = 1990, string $paymentStatus = 'unpaid'): Order
    {
        return Order::query()->create([
            'public_id' => (string) Str::uuid(),
            'order_number' => $number,
            'idempotency_key' => 'test-'.$number,
            'restaurant_id' => $restaurant->id,
            'status' => 'awaiting_restaurant',
            'payment_method' => 'cash',
            'payment_status' => $paymentStatus,
            'fulfilment_type' => 'pickup',
            'currency' => 'AUD',
            'subtotal_cents' => $total,
            'discount_cents' => 0,
            'tax_cents' => 0,
            'service_fee_cents' => 0,
            'delivery_fee_cents' => 0,
            'total_cents' => $total,
            'commission_rate_snapshot' => 0,
            'commission_amount_cents' => 0,
            'restaurant_net_estimate_cents' => $total,
            'placed_at' => now()->subHour(),
            'customer_name_snapshot' => 'Integrity Customer',
        ]);
    }

    private function extraNamedRestaurant(Business $business, string $name): Restaurant
    {
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'trading_name' => $name,
            'legal_business_name' => $name.' Pty Ltd',
            'slug' => 'almost-'.Str::lower(Str::random(6)),
            'status' => RestaurantStatus::Active,
            'ownership_type' => 'third_party',
            'vendor_type' => 'restaurant',
            'accepting_orders' => true,
            'published_at' => now(),
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
        ]);
        app(BusinessHierarchyMigrator::class)->migrateRestaurant($restaurant->fresh());

        return $restaurant->fresh();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $user->roles()->attach($role->id);

        return $user->fresh(['roles.permissions']);
    }
}
