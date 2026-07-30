<?php

namespace Tests\Feature\Offer;

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

class OfferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_create_offer(): void
    {
        [$user] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/restaurant/offers', [
            'name' => '10% Off',
            'offer_type' => 'percentage',
            'value' => 10,
            'is_active' => true,
        ])->assertStatus(201)
            ->assertJsonPath('data.offer.name', '10% Off');
    }

    public function test_create_offer_with_restaurant_target(): void
    {
        [$user, $restaurant] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/restaurant/offers', [
            'name' => 'Restaurant Wide',
            'offer_type' => 'percentage',
            'value' => 5,
            'targets' => [
                ['target_type' => 'restaurant', 'target_id' => $restaurant->id],
            ],
        ])->assertStatus(201);
    }

    public function test_update_offer_with_targets(): void
    {
        [$user, $restaurant] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $offer = $this->postJson('/api/v1/restaurant/offers', [
            'name' => 'Updatable',
            'offer_type' => 'fixed_amount',
            'value' => 500,
        ])->assertStatus(201)->json('data.offer');

        $this->patchJson("/api/v1/restaurant/offers/{$offer['public_id']}", [
            'name' => 'Updated Offer',
            'targets' => [
                ['target_type' => 'restaurant', 'target_id' => $restaurant->id],
            ],
        ])->assertOk()
            ->assertJsonPath('data.offer.name', 'Updated Offer');
    }

    public function test_cross_restaurant_target_rejected(): void
    {
        [$userA] = $this->restaurantOwner();
        [, $restaurantB] = $this->restaurantOwner();

        Sanctum::actingAs($userA);

        $this->postJson('/api/v1/restaurant/offers', [
            'name' => 'Cross Restaurant',
            'offer_type' => 'percentage',
            'value' => 10,
            'targets' => [
                ['target_type' => 'restaurant', 'target_id' => $restaurantB->id],
            ],
        ])->assertStatus(422);
    }

    public function test_list_offers_includes_targets(): void
    {
        [$user, $restaurant] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/restaurant/offers', [
            'name' => 'Listed',
            'offer_type' => 'percentage',
            'value' => 15,
            'targets' => [
                ['target_type' => 'restaurant', 'target_id' => $restaurant->id],
            ],
        ])->assertStatus(201);

        $response = $this->getJson('/api/v1/restaurant/offers')->assertOk();
        $offers = $response->json('data.offers');
        $this->assertNotEmpty($offers);
        $this->assertNotEmpty($offers[0]['targets']);
    }

    public function test_delete_offer(): void
    {
        [$user] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $offer = $this->postJson('/api/v1/restaurant/offers', [
            'name' => 'Deletable',
            'offer_type' => 'fixed_amount',
            'value' => 200,
        ])->assertStatus(201)->json('data.offer');

        $this->deleteJson("/api/v1/restaurant/offers/{$offer['public_id']}")->assertOk();
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
            'manage_restaurant_offers',
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
            'slug' => 'offer-test-' . Str::random(4),
            'legal_business_name' => 'Offer Test Pty Ltd',
            'trading_name' => 'Offer Test Restaurant',
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
