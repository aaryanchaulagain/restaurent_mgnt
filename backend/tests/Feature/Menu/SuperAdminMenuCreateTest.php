<?php

namespace Tests\Feature\Menu;

use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminMenuCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_menu_item_for_platform_restaurant(): void
    {
        $this->seedRoles();
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => 'suvakamana-restaurant',
            'trading_name' => 'Suvakamana Restaurant',
            'legal_business_name' => 'Suvakamana',
            'status' => 'active',
            'ownership_type' => 'first_party',
            'published_at' => now(),
        ]);
        $menu = Menu::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => 'Main menu',
            'status' => 'active',
            'is_default' => true,
        ]);
        $category = MenuCategory::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'name' => 'General',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $admin = User::factory()->create(['email_verified_at' => now(), 'status' => 'active']);
        $role = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $admin->roles()->attach($role->id);
        $admin->load('roles.permissions');

        Sanctum::actingAs($admin);
        Session::put('mfa.verified', true);

        $response = $this->withHeader('X-Restaurant-Id', $restaurant->public_id)
            ->postJson('/api/v1/restaurant/menu-items', [
                'menu_category_public_id' => $category->public_id,
                'name' => 'thakali',
                'short_description' => 'WARM RICE',
                'base_price_cents' => 5000,
                'is_active' => true,
                'is_available' => true,
                'is_featured' => true,
                'is_vegetarian' => true,
                'is_halal' => true,
            ]);

        $response->assertCreated()->assertJsonPath('data.item.name', 'thakali');
        $this->assertDatabaseHas('menu_items', [
            'restaurant_id' => $restaurant->id,
            'name' => 'thakali',
            'base_price_cents' => 5000,
        ]);
    }

    private function seedRoles(): void
    {
        foreach (config('suvakamana.permissions') as $slug) {
            Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }
        foreach (config('suvakamana.roles') as $roleSlug) {
            $role = Role::query()->firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug, 'guard' => 'web']);
            if ($roleSlug === 'super_admin') {
                $role->permissions()->sync(Permission::query()->pluck('id'));
            }
        }
    }
}
