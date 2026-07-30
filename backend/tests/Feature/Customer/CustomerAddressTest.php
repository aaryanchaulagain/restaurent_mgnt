<?php

namespace Tests\Feature\Customer;

use App\Models\CustomerAddress;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_customer_creates_address(): void
    {
        $user = $this->customerUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/customer/addresses', [
            'recipient_name' => 'John Doe',
            'address_line_1' => '123 Main St',
            'suburb' => 'Sydney',
            'state' => 'NSW',
            'postcode' => '2000',
        ])->assertStatus(201)
            ->assertJsonPath('data.address.recipient_name', 'John Doe');
    }

    public function test_customer_lists_addresses(): void
    {
        $user = $this->customerUser();
        Sanctum::actingAs($user);

        CustomerAddress::query()->create([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $user->id,
            'recipient_name' => 'Jane',
            'address_line_1' => '1 Test St',
            'suburb' => 'Sydney',
            'state' => 'NSW',
            'postcode' => '2000',
            'country' => 'AU',
        ]);

        $this->getJson('/api/v1/customer/addresses')->assertOk()
            ->assertJsonCount(1, 'data.addresses');
    }

    public function test_customer_updates_address(): void
    {
        $user = $this->customerUser();
        Sanctum::actingAs($user);

        $address = CustomerAddress::query()->create([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $user->id,
            'recipient_name' => 'Jane',
            'address_line_1' => '1 Test St',
            'suburb' => 'Sydney',
            'state' => 'NSW',
            'postcode' => '2000',
            'country' => 'AU',
        ]);

        $this->patchJson("/api/v1/customer/addresses/{$address->public_id}", [
            'recipient_name' => 'Updated Name',
        ])->assertOk()
            ->assertJsonPath('data.address.recipient_name', 'Updated Name');
    }

    public function test_customer_deletes_address(): void
    {
        $user = $this->customerUser();
        Sanctum::actingAs($user);

        $address = CustomerAddress::query()->create([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $user->id,
            'recipient_name' => 'Jane',
            'address_line_1' => '1 Test St',
            'suburb' => 'Sydney',
            'state' => 'NSW',
            'postcode' => '2000',
            'country' => 'AU',
        ]);

        $this->deleteJson("/api/v1/customer/addresses/{$address->public_id}")->assertOk();
    }

    public function test_setting_default_clears_previous_default(): void
    {
        $user = $this->customerUser();
        Sanctum::actingAs($user);

        $addr1 = CustomerAddress::query()->create([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $user->id,
            'recipient_name' => 'First',
            'address_line_1' => '1 Test St',
            'suburb' => 'Sydney',
            'state' => 'NSW',
            'postcode' => '2000',
            'country' => 'AU',
            'is_default' => true,
        ]);

        $this->postJson('/api/v1/customer/addresses', [
            'recipient_name' => 'Second',
            'address_line_1' => '2 Test St',
            'suburb' => 'Sydney',
            'state' => 'NSW',
            'postcode' => '2001',
            'is_default' => true,
        ])->assertStatus(201);

        $addr1->refresh();
        $this->assertFalse($addr1->is_default);
    }

    public function test_customer_cannot_access_another_customers_address(): void
    {
        $userA = $this->customerUser();
        $userB = $this->customerUser();

        $address = CustomerAddress::query()->create([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $userA->id,
            'recipient_name' => 'Private',
            'address_line_1' => '1 Secret St',
            'suburb' => 'Sydney',
            'state' => 'NSW',
            'postcode' => '2000',
            'country' => 'AU',
        ]);

        Sanctum::actingAs($userB);
        $this->patchJson("/api/v1/customer/addresses/{$address->public_id}", [
            'recipient_name' => 'Hacked',
        ])->assertStatus(404);
    }

    public function test_guest_cannot_access_address_api(): void
    {
        $this->getJson('/api/v1/customer/addresses')->assertStatus(401);
    }

    private function seedPermissions(): void
    {
        foreach (config('suvakamana.permissions') as $slug) {
            Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }
        $role = Role::query()->firstOrCreate(['slug' => 'customer'], ['name' => 'Customer', 'guard' => 'web']);
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', ['manage_own_addresses'])->pluck('id')
        );
    }

    private function customerUser(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'customer')->firstOrFail();
        $user->roles()->attach($role->id);
        $user->load('roles.permissions');

        return $user;
    }
}
