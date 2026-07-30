<?php

namespace Tests\Feature\Hours;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RestaurantHoursApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_owner_retrieves_hours(): void
    {
        [$user] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/restaurant/hours')->assertOk()
            ->assertJsonStructure(['data' => ['hours']]);
    }

    public function test_owner_saves_valid_split_hours(): void
    {
        [$user] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/restaurant/hours', [
            'hours' => [
                ['day_of_week' => 1, 'opens_at' => '08:00', 'closes_at' => '12:00', 'is_closed' => false],
                ['day_of_week' => 1, 'opens_at' => '14:00', 'closes_at' => '22:00', 'is_closed' => false],
            ],
        ])->assertOk();
    }

    public function test_same_day_overlap_rejected(): void
    {
        [$user] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/restaurant/hours', [
            'hours' => [
                ['day_of_week' => 1, 'opens_at' => '08:00', 'closes_at' => '14:00', 'is_closed' => false],
                ['day_of_week' => 1, 'opens_at' => '13:00', 'closes_at' => '22:00', 'is_closed' => false],
            ],
        ])->assertStatus(422);
    }

    public function test_overnight_period_accepted(): void
    {
        [$user] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/restaurant/hours', [
            'hours' => [
                ['day_of_week' => 5, 'opens_at' => '18:00', 'closes_at' => '02:00', 'is_closed' => false],
            ],
        ])->assertOk();
    }

    public function test_special_closure_creation(): void
    {
        [$user] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/restaurant/special-hours', [
            'date' => now()->addDays(7)->toDateString(),
            'is_closed' => true,
            'reason' => 'Public holiday',
        ])->assertStatus(201);
    }

    public function test_special_hours_returned(): void
    {
        [$user] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/restaurant/special-hours', [
            'date' => now()->addDays(10)->toDateString(),
            'is_closed' => true,
        ])->assertStatus(201);

        $this->getJson('/api/v1/restaurant/special-hours')->assertOk()
            ->assertJsonStructure(['data' => ['special_hours']]);
    }

    public function test_preview_returns_timezone_and_open_state(): void
    {
        [$user] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/restaurant/hours/preview')->assertOk()
            ->assertJsonStructure(['data' => ['timezone', 'is_open']]);
    }

    public function test_cross_restaurant_hours_forbidden(): void
    {
        [$userA] = $this->restaurantOwner();
        [$userB, $restaurantB] = $this->restaurantOwner();

        Sanctum::actingAs($userA);

        $this->putJson('/api/v1/restaurant/hours', [
            'hours' => [
                ['day_of_week' => 1, 'opens_at' => '09:00', 'closes_at' => '17:00', 'is_closed' => false],
            ],
        ])->assertOk();

        // userA's hours should not affect restaurantB
        Sanctum::actingAs($userB);
        $response = $this->getJson('/api/v1/restaurant/hours')->assertOk();
        $this->assertEmpty($response->json('data.hours'));
    }

    private function seedPermissions(): void
    {
        foreach (config('suvakamana.permissions') as $slug) {
            Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }
        $ownerRole = Role::query()->firstOrCreate(['slug' => 'restaurant_owner'], ['name' => 'Owner', 'guard' => 'web']);
        $ownerSlugs = [
            'view_restaurant_dashboard', 'view_restaurant_profile', 'manage_restaurant_profile',
            'manage_restaurant_media', 'manage_restaurant_hours', 'manage_restaurant_service_areas',
            'activate_restaurant', 'temporarily_close_restaurant',
            'view_menu', 'manage_menu_categories', 'manage_menu_items', 'manage_menu_variants',
            'manage_menu_modifiers', 'manage_menu_allergens', 'manage_menu_availability',
        ];
        $ownerRole->permissions()->sync(Permission::query()->whereIn('slug', $ownerSlugs)->pluck('id'));
    }

    /** @return array{0: User, 1: Restaurant} */
    private function restaurantOwner(): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->where('slug', 'restaurant_owner')->firstOrFail();
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'hours-test-' . Str::random(4),
            'legal_business_name' => 'Hours Test Pty Ltd',
            'trading_name' => 'Hours Test Restaurant',
            'status' => RestaurantStatus::Active,
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
        $user->load('roles.permissions');

        return [$user, $restaurant];
    }
}
