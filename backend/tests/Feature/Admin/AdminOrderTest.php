<?php

namespace Tests\Feature\Admin;

use App\Enums\Partner\RestaurantStatus;
use App\Models\MfaMethod;
use App\Models\Order;
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

class AdminOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_authorised_super_admin_can_list_orders(): void
    {
        $admin = $this->superAdmin(withMfa: true);
        $this->seedOrder(['order_number' => 'SVK-ADMIN-001']);

        Sanctum::actingAs($admin);
        Session::put('mfa.verified', true);

        $this->getJson('/api/v1/admin/orders')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['orders'], 'meta' => ['current_page', 'total']]);
    }

    public function test_mfa_is_required_for_super_admin_with_mfa_enabled(): void
    {
        Session::forget('mfa.verified');
        $admin = $this->superAdmin(withMfa: true);
        $admin->refresh()->load('mfaMethod');
        $this->assertTrue($admin->hasConfirmedMfa());

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/orders')
            ->assertStatus(403)
            ->assertJsonPath('code', 'mfa_required');
    }

    public function test_missing_permission_returns_403(): void
    {
        $admin = $this->superAdmin(withMfa: true, permissions: ['view_platform_order_details']);
        Sanctum::actingAs($admin);
        Session::put('mfa.verified', true);

        $this->getJson('/api/v1/admin/orders')->assertStatus(403);
    }

    public function test_restaurant_user_cannot_access_platform_order_apis(): void
    {
        $restaurant = $this->restaurant();
        [$owner] = $this->restaurantOwner($restaurant);
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/admin/orders')->assertStatus(403);
    }

    public function test_customer_cannot_access_platform_order_apis(): void
    {
        $customer = $this->customer();
        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/admin/orders')->assertStatus(403);
    }

    public function test_super_admin_can_show_order_and_audit_view(): void
    {
        $admin = $this->superAdmin(withMfa: true);
        $order = $this->seedOrder([
            'order_number' => 'SVK-ADMIN-SHOW',
            'idempotency_key' => 'admin-show-key',
            'idempotency_payload_hash' => hash('sha256', 'secret-hash'),
        ]);

        Sanctum::actingAs($admin);
        Session::put('mfa.verified', true);

        $response = $this->getJson('/api/v1/admin/orders/'.$order->public_id)
            ->assertOk()
            ->assertJsonPath('data.order.order_number', 'SVK-ADMIN-SHOW')
            ->assertJsonPath('data.order.idempotency.recorded', true)
            ->assertJsonPath('data.order.idempotency.replay_safe', true);

        $encoded = json_encode($response->json());
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('idempotency_payload_hash', $encoded);
        $this->assertStringNotContainsString('admin-show-key', $encoded);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'action' => 'admin.order.viewed',
            'auditable_type' => Order::class,
            'auditable_id' => $order->id,
        ]);
    }

    public function test_show_without_detail_permission_returns_403(): void
    {
        $admin = $this->superAdmin(withMfa: true, permissions: ['view_all_platform_orders']);
        $order = $this->seedOrder();

        Sanctum::actingAs($admin);
        Session::put('mfa.verified', true);

        $this->getJson('/api/v1/admin/orders/'.$order->public_id)->assertStatus(403);
    }

    public function test_filters_and_pagination_work(): void
    {
        $admin = $this->superAdmin(withMfa: true);
        $firstParty = $this->restaurant('first_party');
        $thirdParty = $this->restaurant('third_party');

        $match = $this->seedOrder([
            'order_number' => 'SVK-FILTER-001',
            'restaurant_id' => $firstParty->id,
            'status' => 'awaiting_restaurant',
            'customer_email_snapshot' => 'filter@example.test',
            'total_cents' => 4500,
        ]);
        $this->seedOrder([
            'order_number' => 'SVK-FILTER-002',
            'restaurant_id' => $thirdParty->id,
            'status' => 'completed_pickup',
            'customer_email_snapshot' => 'other@example.test',
            'total_cents' => 9000,
        ]);

        Sanctum::actingAs($admin);
        Session::put('mfa.verified', true);

        $this->getJson('/api/v1/admin/orders?'.http_build_query([
            'order_number' => 'FILTER-001',
            'status' => 'awaiting_restaurant',
            'ownership_type' => 'first_party',
            'customer_email' => 'filter@example',
            'min_total_cents' => 4000,
            'max_total_cents' => 5000,
            'per_page' => 1,
        ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.orders.0.order_number', $match->order_number);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function superAdmin(bool $withMfa = false, array $permissions = ['view_all_platform_orders', 'view_platform_order_details']): User
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'email_verified_at' => now(),
        ]);

        $role = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', $permissions)->pluck('id')
        );
        $user->roles()->attach($role->id);
        $user->load('roles.permissions');

        if ($withMfa) {
            MfaMethod::query()->create([
                'user_id' => $user->id,
                'type' => 'totp',
                'secret_encrypted' => 'test-secret',
                'is_confirmed' => true,
                'is_primary' => true,
                'confirmed_at' => now(),
            ]);
        }

        return $user;
    }

    private function customer(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('slug', 'customer')->firstOrFail()->id);
        $user->load('roles.permissions');

        return $user;
    }

    /** @return array{0: User, 1: Restaurant} */
    private function restaurantOwner(Restaurant $restaurant): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'restaurant_owner')->firstOrFail();

        RestaurantUser::query()->create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $user->roles()->attach($role->id, ['restaurant_id' => $restaurant->id]);
        $user->load('roles.permissions');

        return [$user, $restaurant];
    }

    private function restaurant(string $ownershipType = 'first_party'): Restaurant
    {
        return Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'admin-'.Str::lower(Str::random(8)),
            'legal_business_name' => 'Admin Order Test Pty Ltd',
            'trading_name' => 'Admin Order Kitchen',
            'ownership_type' => $ownershipType,
            'status' => RestaurantStatus::Active,
            'published_at' => now(),
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function seedOrder(array $overrides = []): Order
    {
        $restaurant = $this->restaurant();

        return Order::query()->create(array_merge([
            'public_id' => (string) Str::uuid(),
            'order_number' => 'SVK-'.Str::upper(Str::random(10)),
            'idempotency_key' => 'seed-'.Str::lower(Str::random(8)),
            'restaurant_id' => $restaurant->id,
            'status' => 'awaiting_restaurant',
            'payment_method' => 'cash',
            'payment_status' => 'unpaid',
            'fulfilment_type' => 'pickup',
            'currency' => 'AUD',
            'customer_name_snapshot' => 'Test Customer',
            'customer_email_snapshot' => 'customer@example.test',
            'subtotal_cents' => 2500,
            'discount_cents' => 0,
            'tax_cents' => 0,
            'service_fee_cents' => 0,
            'delivery_fee_cents' => 0,
            'total_cents' => 2500,
            'commission_rate_snapshot' => 0,
            'commission_amount_cents' => 0,
            'restaurant_net_estimate_cents' => 2500,
            'placed_at' => now(),
        ], $overrides));
    }

    private function seedPermissions(): void
    {
        foreach (config('suvakamana.permissions') as $slug) {
            Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }

        $superAdmin = Role::query()->firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin', 'guard' => 'web']);
        $customer = Role::query()->firstOrCreate(['slug' => 'customer'], ['name' => 'Customer', 'guard' => 'web']);
        $owner = Role::query()->firstOrCreate(['slug' => 'restaurant_owner'], ['name' => 'Owner', 'guard' => 'web']);

        $superAdmin->permissions()->sync(
            Permission::query()->whereIn('slug', [
                'view_all_platform_orders',
                'view_platform_order_details',
                'manage_order_exceptions',
            ])->pluck('id')
        );

        $customer->permissions()->sync([]);
        $owner->permissions()->sync(
            Permission::query()->whereIn('slug', ['view_restaurant_orders'])->pluck('id')
        );
    }
}
