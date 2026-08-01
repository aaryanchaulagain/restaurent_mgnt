<?php

namespace Tests\Feature\Business;

use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantAddress;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use App\Services\Business\BusinessHierarchyMigrator;
use App\Support\BusinessRoles;
use App\Support\BusinessTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessHierarchyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_restaurant_migrates_to_one_business_and_default_branch(): void
    {
        $this->seedRoles();
        $restaurant = $this->makeRestaurant('aryan-bakery', vendorType: 'bakery');
        RestaurantAddress::query()->create([
            'restaurant_id' => $restaurant->id,
            'address_type' => 'storefront',
            'address_line_1' => '12 Main St',
            'suburb' => 'Itahari',
            'state' => 'Koshi',
            'postcode' => '56705',
            'country' => 'NP',
            'latitude' => -26.1234567,
            'longitude' => 153.1234567,
            'is_primary' => true,
        ]);
        $owner = $this->assignRestaurantRole($restaurant, 'restaurant_owner');
        $manager = $this->assignRestaurantRole($restaurant, 'restaurant_manager');
        $staff = $this->assignRestaurantRole($restaurant, 'restaurant_staff');

        $result = app(BusinessHierarchyMigrator::class)->migrateRestaurant($restaurant->fresh());

        $business = $result['business'];
        $branch = $result['branch'];

        $this->assertInstanceOf(Business::class, $business);
        $this->assertInstanceOf(Branch::class, $branch);
        $this->assertSame(BusinessTypes::BAKERY, $business->business_type);
        $this->assertSame($owner->id, $business->owner_user_id);
        $this->assertTrue($branch->is_default);
        $this->assertSame('MAIN', $branch->code);
        $this->assertSame($restaurant->id, $branch->restaurant_id);
        $this->assertSame('12 Main St', $branch->address_line);
        $this->assertSame('Itahari', $branch->city);

        $restaurant->refresh();
        $this->assertSame($business->id, $restaurant->business_id);
        $this->assertSame($branch->id, $restaurant->branch_id);

        $this->assertDatabaseHas('business_users', [
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'role' => BusinessRoles::BUSINESS_OWNER,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('branch_users', [
            'branch_id' => $branch->id,
            'user_id' => $owner->id,
            'role' => BusinessRoles::BRANCH_MANAGER,
        ]);
        $this->assertDatabaseHas('branch_users', [
            'branch_id' => $branch->id,
            'user_id' => $manager->id,
            'role' => BusinessRoles::BRANCH_MANAGER,
        ]);
        $this->assertDatabaseHas('branch_users', [
            'branch_id' => $branch->id,
            'user_id' => $staff->id,
            'role' => BusinessRoles::ORDER_MANAGER,
        ]);
        $this->assertDatabaseMissing('business_users', [
            'business_id' => $business->id,
            'user_id' => $manager->id,
        ]);
    }

    public function test_migrate_all_is_idempotent(): void
    {
        $this->seedRoles();
        $this->makeRestaurant('kitchen-one');
        $this->makeRestaurant('kitchen-two');

        $migrator = app(BusinessHierarchyMigrator::class);
        $first = $migrator->migrateAll();
        $second = $migrator->migrateAll();

        $this->assertSame(2, $first['migrated']);
        $this->assertSame(0, $first['skipped']);
        $this->assertSame(0, $second['migrated']);
        $this->assertSame(2, $second['skipped']);
        $this->assertSame(2, Business::query()->count());
        $this->assertSame(2, Branch::query()->count());
    }

    public function test_business_owner_cannot_view_another_business(): void
    {
        $this->seedRoles();
        $a = $this->migratedRestaurant('biz-a');
        $b = $this->migratedRestaurant('biz-b');
        $ownerA = User::query()->findOrFail(
            BusinessUser::query()->where('business_id', $a['business']->id)->value('user_id')
        );
        $ownerA->load('roles.permissions');

        $this->assertTrue(Gate::forUser($ownerA)->allows('view', $a['business']));
        $this->assertTrue(Gate::forUser($ownerA)->denies('view', $b['business']));
        $this->assertTrue(Gate::forUser($ownerA)->denies('view', $b['branch']));
        $this->assertTrue(Gate::forUser($ownerA)->allows('manageBranches', $a['business']));
    }

    public function test_branch_manager_cannot_access_another_branch(): void
    {
        $this->seedRoles();
        $a = $this->migratedRestaurant('branch-a');
        $b = $this->migratedRestaurant('branch-b');

        $manager = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        BranchUser::query()->create([
            'branch_id' => $a['branch']->id,
            'user_id' => $manager->id,
            'role' => BusinessRoles::BRANCH_MANAGER,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->assertTrue(Gate::forUser($manager)->allows('view', $a['branch']));
        $this->assertTrue(Gate::forUser($manager)->allows('update', $a['branch']));
        $this->assertTrue(Gate::forUser($manager)->denies('view', $b['branch']));
        $this->assertTrue(Gate::forUser($manager)->denies('view', $a['business']));
        $this->assertTrue(Gate::forUser($manager)->denies('manageBranches', $a['business']));
    }

    public function test_super_admin_can_access_all_businesses_and_branches(): void
    {
        $this->seedRoles();
        $a = $this->migratedRestaurant('admin-a');
        $b = $this->migratedRestaurant('admin-b');
        $admin = $this->superAdmin();

        $this->assertTrue(Gate::forUser($admin)->allows('view', $a['business']));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $b['business']));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $a['branch']));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $b['branch']));
        $this->assertTrue(Gate::forUser($admin)->allows('suspend', $a['business']));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $b['branch']));
    }

    public function test_artisan_command_migrates_restaurants(): void
    {
        $this->seedRoles();
        $restaurant = $this->makeRestaurant('cmd-kitchen');
        $this->assignRestaurantRole($restaurant, 'restaurant_owner');

        $this->artisan('khana:migrate-business-hierarchy')
            ->assertSuccessful();

        $restaurant->refresh();
        $this->assertNotNull($restaurant->business_id);
        $this->assertNotNull($restaurant->branch_id);
        $this->assertDatabaseHas('branches', [
            'restaurant_id' => $restaurant->id,
            'is_default' => 1,
        ]);
    }

    /** @return array{business: Business, branch: Branch, restaurant: Restaurant} */
    private function migratedRestaurant(string $slug): array
    {
        $restaurant = $this->makeRestaurant($slug);
        $this->assignRestaurantRole($restaurant, 'restaurant_owner');
        $result = app(BusinessHierarchyMigrator::class)->migrateRestaurant($restaurant->fresh());

        return [
            'business' => $result['business'],
            'branch' => $result['branch'],
            'restaurant' => $restaurant->fresh(),
        ];
    }

    private function makeRestaurant(string $slug = 'test-kitchen', ?string $vendorType = 'restaurant'): Restaurant
    {
        return Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'trading_name' => Str::headline($slug),
            'legal_business_name' => Str::headline($slug).' Pty Ltd',
            'slug' => $slug,
            'status' => 'active',
            'ownership_type' => 'third_party',
            'vendor_type' => $vendorType,
            'business_email' => $slug.'@example.com',
            'business_phone' => '+61400000000',
            'timezone' => 'Australia/Sydney',
            'accepting_orders' => true,
            'minimum_order_cents' => 1500,
        ]);
    }

    private function assignRestaurantRole(Restaurant $restaurant, string $roleSlug): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
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

    private function superAdmin(): User
    {
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

        $super = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $super->permissions()->sync(
            Permission::query()->whereIn('slug', ['view_super_admin_dashboard', 'manage_restaurants'])->pluck('id')
        );
    }
}
