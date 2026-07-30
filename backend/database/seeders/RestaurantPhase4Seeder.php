<?php

namespace Database\Seeders;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Cuisine;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\RestaurantAddress;
use App\Models\RestaurantApplication;
use App\Models\RestaurantCommissionAgreement;
use App\Models\RestaurantOpeningHour;
use App\Models\RestaurantServiceArea;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RestaurantPhase4Seeder extends Seeder
{
    public function run(): void
    {
        $nepalese = Cuisine::query()->where('slug', 'nepalese')->first();
        $restaurant = Restaurant::query()->where('slug', 'himalayan-kitchen')->first();
        if (! $restaurant || ! $nepalese) {
            return;
        }

        $restaurant->forceFill([
            'short_description' => 'Mountain-inspired thalis and tandoor breads.',
            'description' => 'Himalayan Kitchen serves slow-cooked dals, momos and smoky tandoor breads in Sydney.',
            'business_email' => 'hello@himalayan-kitchen.test',
            'business_phone' => '+61400111222',
            'primary_cuisine_id' => $nepalese->id,
            'price_level' => 'moderate',
            'minimum_order_cents' => 1500,
            'average_preparation_minutes' => 25,
            'pickup_enabled' => true,
            'restaurant_delivery_enabled' => true,
            'logo_path' => 'seed/himalayan-logo.png',
            'cover_image_path' => 'seed/himalayan-cover.png',
            'status' => RestaurantStatus::Active,
            'published_at' => now()->subDay(),
        ])->save();

        $restaurant->cuisines()->syncWithoutDetaching([
            $nepalese->id => ['is_primary' => true],
        ]);

        RestaurantAddress::query()->updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'is_primary' => true],
            [
                'address_type' => 'primary',
                'address_line_1' => '42 George Street',
                'suburb' => 'Sydney',
                'state' => 'NSW',
                'postcode' => '2000',
                'country' => 'AU',
                'latitude' => -33.8688,
                'longitude' => 151.2093,
            ],
        );

        $owner = User::query()->where('email', env('SEED_RESTAURANT_OWNER_EMAIL', 'owner@example.com'))->first();
        $admin = User::query()->where('email', env('SEED_SUPER_ADMIN_EMAIL', 'admin@example.com'))->first();

        $application = RestaurantApplication::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id],
            [
                'public_id' => (string) Str::uuid(),
                'applicant_user_id' => $owner?->id ?? $restaurant->id,
                'status' => 'approved',
                'legal_business_name' => $restaurant->legal_business_name,
                'trading_name' => $restaurant->trading_name,
                'business_email' => 'hello@himalayan-kitchen.test',
                'business_phone' => '+61400111222',
                'primary_contact_name' => 'Hari Thapa',
                'primary_contact_email' => $owner?->email ?? 'owner@example.com',
                'primary_contact_phone' => '+61400111222',
                'version' => 1,
            ],
        );

        if ($owner && $admin) {
            RestaurantCommissionAgreement::query()->updateOrCreate(
                ['application_id' => $application->id, 'status' => 'accepted'],
                [
                    'restaurant_id' => $restaurant->id,
                    'commission_type' => 'percentage',
                    'commission_rate' => '12.50',
                    'fixed_fee_cents' => 0,
                    'settlement_frequency' => 'weekly',
                    'effective_from' => now()->toDateString(),
                    'created_by' => $admin->id,
                    'accepted_by' => $owner->id,
                    'accepted_at' => now()->subWeek(),
                    'terms_version' => config('partner.terms_version'),
                ],
            );
        }

        for ($day = 0; $day <= 6; $day++) {
            RestaurantOpeningHour::query()->firstOrCreate(
                ['restaurant_id' => $restaurant->id, 'day_of_week' => $day, 'service_type' => 'all'],
                ['opens_at' => '11:00', 'closes_at' => '21:30', 'is_closed' => false],
            );
        }

        RestaurantServiceArea::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'type' => 'postcode', 'postcode' => '2000'],
            [
                'minimum_order_cents' => 1500,
                'delivery_fee_cents' => 499,
                'free_delivery_threshold_cents' => 5000,
                'estimated_minutes' => 35,
                'is_active' => true,
            ],
        );

        $menu = Menu::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'is_default' => true],
            [
                'public_id' => (string) Str::uuid(),
                'name' => 'Main menu',
                'status' => 'active',
            ],
        );

        $mains = MenuCategory::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'menu_id' => $menu->id, 'name' => 'Mains'],
            ['public_id' => (string) Str::uuid(), 'is_active' => true, 'sort_order' => 0],
        );

        MenuItem::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'slug' => 'buff-momo'],
            [
                'public_id' => (string) Str::uuid(),
                'menu_id' => $menu->id,
                'menu_category_id' => $mains->id,
                'name' => 'Buff Momo',
                'short_description' => 'Steamed dumplings with tomato achaar.',
                'base_price_cents' => 1250,
                'compare_at_price_cents' => 1490,
                'is_active' => true,
                'is_available' => true,
                'is_featured' => true,
            ],
        );

        MenuItem::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'slug' => 'dal-bhat-set'],
            [
                'public_id' => (string) Str::uuid(),
                'menu_id' => $menu->id,
                'menu_category_id' => $mains->id,
                'name' => 'Dal Bhat Set',
                'short_description' => 'Lentils, rice, seasonal sabzi and pickles.',
                'base_price_cents' => 1800,
                'is_active' => true,
                'is_available' => true,
            ],
        );

        $pending = Restaurant::query()->firstOrCreate(
            ['slug' => 'harbour-spice-pending'],
            [
                'public_id' => (string) Str::uuid(),
                'legal_business_name' => 'Harbour Spice Pty Ltd',
                'trading_name' => 'Harbour Spice',
                'status' => RestaurantStatus::PendingSetup,
                'verification_status' => 'verified',
                'timezone' => config('restaurant.default_timezone'),
                'currency' => config('restaurant.default_currency'),
                'approved_at' => now(),
            ],
        );

        $ownerRole = Role::query()->where('slug', 'restaurant_owner')->first();
        $pendingOwner = User::query()->firstOrCreate(
            ['email' => 'pending-owner@example.com'],
            [
                'first_name' => 'Pending',
                'last_name' => 'Owner',
                'password' => bcrypt('Password1!'),
                'email_verified_at' => now(),
            ],
        );
        if ($ownerRole) {
            RestaurantUser::query()->firstOrCreate(
                ['restaurant_id' => $pending->id, 'user_id' => $pendingOwner->id],
                ['role_id' => $ownerRole->id, 'status' => 'active', 'joined_at' => now()],
            );
            $pendingOwner->roles()->syncWithoutDetaching([
                $ownerRole->id => ['restaurant_id' => $pending->id],
            ]);
        }
    }
}
