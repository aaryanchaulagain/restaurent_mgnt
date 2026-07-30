<?php

namespace Tests\Feature\Media;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RestaurantMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
        Storage::fake('local');
    }

    public function test_valid_jpeg_upload(): void
    {
        [$user] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $jpegHeader = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
        $tmp = tempnam(sys_get_temp_dir(), 'jpg');
        file_put_contents($tmp, $jpegHeader . str_repeat("\x00", 1000));
        $file = new UploadedFile($tmp, 'logo.jpg', 'image/jpeg', null, true);

        $this->post('/api/v1/restaurant/media/logo', [
            'file' => $file,
        ])->assertOk()
            ->assertJsonStructure(['data' => ['logo_path']]);
    }

    public function test_invalid_mime_exe_rejected(): void
    {
        [$user] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $this->post('/api/v1/restaurant/media/logo', [
            'file' => UploadedFile::fake()->create('malware.exe', 100, 'application/octet-stream'),
        ])->assertStatus(422);
    }

    public function test_svg_rejected(): void
    {
        [$user] = $this->restaurantOwner();
        Sanctum::actingAs($user);

        $this->post('/api/v1/restaurant/media/logo', [
            'file' => UploadedFile::fake()->create('icon.svg', 50, 'image/svg+xml'),
        ])->assertStatus(422);
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
            'slug' => 'media-test-' . Str::random(4),
            'legal_business_name' => 'Media Test Pty Ltd',
            'trading_name' => 'Media Test Restaurant',
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
