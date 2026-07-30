<?php

namespace Tests\Feature\Restaurant;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Cuisine;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantAddress;
use App\Models\RestaurantApplication;
use App\Models\RestaurantCommissionAgreement;
use App\Models\RestaurantOpeningHour;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RestaurantProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
        Storage::fake('local');
    }

    public function test_owner_can_view_profile(): void
    {
        [$user, $restaurant] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/restaurant/profile')->assertOk()
            ->assertJsonPath('data.profile.trading_name', $restaurant->trading_name);
    }

    public function test_staff_without_profile_permission_gets_forbidden_on_update(): void
    {
        [$owner, $restaurant] = $this->restaurantOwner();
        $staff = $this->attachStaff($restaurant, permissions: ['view_restaurant_dashboard', 'view_menu']);
        Sanctum::actingAs($staff);

        $this->patchJson('/api/v1/restaurant/profile', ['trading_name' => 'Changed'])->assertForbidden();
    }

    public function test_checklist_reflects_missing_fields(): void
    {
        [$user, $restaurant] = $this->restaurantOwner(pending: true);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/restaurant/profile/checklist')->assertOk();
        $this->assertFalse($response->json('data.can_activate'));
        $this->assertNotEmpty($response->json('data.missing'));
    }

    public function test_incomplete_restaurant_cannot_activate(): void
    {
        [$user] = $this->restaurantOwner(pending: true);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/restaurant/profile/activate')->assertStatus(422);
    }

    public function test_complete_restaurant_can_activate(): void
    {
        [$user, $restaurant] = $this->fullySetupRestaurant();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/restaurant/profile/activate')->assertOk()
            ->assertJsonPath('data.profile.status', RestaurantStatus::Active->value);

        $restaurant->refresh();
        $this->assertNotNull($restaurant->published_at);
    }

    public function test_public_list_hides_pending_setup(): void
    {
        [$user] = $this->fullySetupRestaurant();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/restaurant/profile/activate')->assertOk();

        Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'hidden-pending',
            'legal_business_name' => 'Hidden Pty Ltd',
            'trading_name' => 'Hidden Pending',
            'status' => RestaurantStatus::PendingSetup,
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
        ]);

        $slugs = collect($this->getJson('/api/v1/public/restaurants')->json('data.restaurants'))
            ->pluck('slug')
            ->filter();
        $this->assertTrue($slugs->contains('test-kitchen'), 'Expected test-kitchen in: '.$slugs->join(', '));
        $this->assertFalse($slugs->contains('hidden-pending'));
    }

    public function test_public_menu_excludes_cost_price(): void
    {
        [$user] = $this->fullySetupRestaurant();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/restaurant/profile/activate')->assertOk();

        $payload = $this->getJson('/api/v1/public/restaurants/test-kitchen/menu')->assertOk()->json('data.items.0');
        $this->assertArrayNotHasKey('cost_price_cents', $payload);
    }

    public function test_logo_upload_validates(): void
    {
        [$user] = $this->restaurantOwner();
        $user->refresh()->load('roles.permissions');
        $this->assertTrue($user->hasPermission('manage_restaurant_media'));
        $this->actingAs($user, 'sanctum');

        $response = $this->post('/api/v1/restaurant/media/logo', [
            'file' => UploadedFile::fake()->create('bad.exe', 100, 'application/octet-stream'),
        ]);

        $response->assertStatus(422);
    }

    private function seedPermissions(): void
    {
        foreach (config('suvakamana.permissions') as $slug) {
            Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }
        $ownerRole = Role::query()->firstOrCreate(['slug' => 'restaurant_owner'], ['name' => 'Owner', 'guard' => 'web']);
        $staffRole = Role::query()->firstOrCreate(['slug' => 'restaurant_staff'], ['name' => 'Staff', 'guard' => 'web']);
        $ownerSlugs = [
            'view_restaurant_dashboard', 'view_restaurant_profile', 'manage_restaurant_profile',
            'manage_restaurant_media', 'manage_restaurant_hours', 'manage_restaurant_service_areas',
            'activate_restaurant', 'temporarily_close_restaurant',
            'view_menu', 'manage_menu_categories', 'manage_menu_items', 'manage_menu_variants',
            'manage_menu_modifiers', 'manage_menu_allergens', 'manage_menu_availability',
        ];
        $ownerRole->permissions()->sync(Permission::query()->whereIn('slug', $ownerSlugs)->pluck('id'));
        $staffRole->permissions()->sync(Permission::query()->whereIn('slug', ['view_restaurant_dashboard', 'view_menu'])->pluck('id'));
    }

    /** @return array{0: User, 1: Restaurant} */
    private function restaurantOwner(bool $pending = false): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'restaurant_owner')->firstOrFail();
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'owner-restaurant-'.Str::random(4),
            'legal_business_name' => 'Owner Co Pty Ltd',
            'trading_name' => 'Owner Restaurant',
            'status' => $pending ? RestaurantStatus::PendingSetup : RestaurantStatus::Active,
            'timezone' => 'Australia/Sydney',
            'currency' => 'AUD',
        ]);
        RestaurantUser::query()->create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $user->roles()->attach($role->id, ['restaurant_id' => $restaurant->id]);

        return [$user, $restaurant];
    }

    private function attachStaff(Restaurant $restaurant, array $permissions): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->firstOrCreate(['slug' => 'restaurant_staff'], ['name' => 'Staff', 'guard' => 'web']);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id'));
        RestaurantUser::query()->create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $user->roles()->attach($role->id, ['restaurant_id' => $restaurant->id]);

        return $user;
    }

    /** @return array{0: User, 1: Restaurant} */
    private function fullySetupRestaurant(): array
    {
        [$user, $restaurant] = $this->restaurantOwner(pending: true);
        $restaurant->forceFill([
            'slug' => 'test-kitchen',
            'trading_name' => 'Test Kitchen',
            'description' => 'Complete setup restaurant',
            'business_email' => 'test@kitchen.test',
            'business_phone' => '+61400000001',
            'logo_path' => 'logo.png',
            'cover_image_path' => 'cover.png',
            'pickup_enabled' => true,
        ])->save();

        $cuisine = Cuisine::query()->create(['name' => 'Test', 'slug' => 'test-'.Str::random(4), 'is_active' => true]);
        $restaurant->update(['primary_cuisine_id' => $cuisine->id]);
        $restaurant->cuisines()->sync([$cuisine->id => ['is_primary' => true]]);

        RestaurantAddress::query()->create([
            'restaurant_id' => $restaurant->id,
            'address_type' => 'primary',
            'address_line_1' => '1 Test St',
            'suburb' => 'Sydney',
            'state' => 'NSW',
            'postcode' => '2000',
            'country' => 'AU',
            'is_primary' => true,
        ]);

        RestaurantOpeningHour::query()->create([
            'restaurant_id' => $restaurant->id,
            'day_of_week' => 1,
            'opens_at' => '09:00',
            'closes_at' => '17:00',
            'is_closed' => false,
        ]);

        $app = RestaurantApplication::query()->create([
            'public_id' => (string) Str::uuid(),
            'applicant_user_id' => $user->id,
            'restaurant_id' => $restaurant->id,
            'status' => 'approved',
            'legal_business_name' => $restaurant->legal_business_name,
            'trading_name' => $restaurant->trading_name,
            'business_email' => 'test@kitchen.test',
            'business_phone' => '+61400000001',
            'primary_contact_name' => 'Test',
            'primary_contact_email' => $user->email,
            'primary_contact_phone' => '+61400000001',
            'version' => 1,
        ]);

        RestaurantCommissionAgreement::query()->create([
            'restaurant_id' => $restaurant->id,
            'application_id' => $app->id,
            'commission_type' => 'percentage',
            'commission_rate' => '10.00',
            'status' => 'accepted',
            'created_by' => $user->id,
            'accepted_by' => $user->id,
            'accepted_at' => now(),
        ]);

        $menu = Menu::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Main',
            'status' => 'active',
            'is_default' => true,
        ]);
        $category = MenuCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'name' => 'Mains',
            'is_active' => true,
        ]);
        MenuItem::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'menu_category_id' => $category->id,
            'name' => 'Test Item',
            'slug' => 'test-item',
            'base_price_cents' => 1250,
            'cost_price_cents' => 500,
            'is_active' => true,
            'is_available' => true,
        ]);

        $restaurant->update(['status' => RestaurantStatus::PendingSetup, 'published_at' => null]);

        return [$user, $restaurant];
    }
}
