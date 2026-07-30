<?php

namespace Database\Seeders;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Restaurant;
use App\Models\RestaurantCommissionAgreement;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo "sold panel" restaurant + owner, and a Suvakamana platform menu operator.
 */
class SoldRestaurantSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('SEED_SOLD_OWNER_PASSWORD') ?: (app()->environment('production') ? null : 'Password1!');
        if (! $password) {
            return;
        }

        $ownerEmail = env('SEED_SOLD_OWNER_EMAIL', 'sold-owner@example.com');
        $owner = User::query()->firstOrCreate(
            ['email' => $ownerEmail],
            [
                'first_name' => 'Sold',
                'last_name' => 'Owner',
                'password' => Hash::make($password),
                'status' => 'active',
                'email_verified_at' => now(),
            ],
        );

        $restaurant = Restaurant::query()->firstOrCreate(
            ['slug' => 'sold-panels-kitchen'],
            [
                'public_id' => (string) Str::uuid(),
                'legal_business_name' => 'Sold Panels Kitchen Pty Ltd',
                'trading_name' => 'Sold Panels Kitchen',
                'status' => RestaurantStatus::Active,
                'verification_status' => 'verified',
                'ownership_type' => 'third_party',
                'timezone' => config('partner.default_timezone'),
                'currency' => config('partner.default_currency'),
                'published_at' => now(),
                'accepting_orders' => true,
                'pickup_enabled' => true,
                'approved_at' => now(),
            ],
        );

        $ownerRole = Role::query()->where('slug', 'restaurant_owner')->first();
        if (! $ownerRole) {
            return;
        }
        RestaurantUser::query()->updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'user_id' => $owner->id],
            ['role_id' => $ownerRole->id, 'status' => 'active', 'joined_at' => now()],
        );
        $owner->roles()->syncWithoutDetaching([
            $ownerRole->id => ['restaurant_id' => $restaurant->id],
        ]);

        if (! RestaurantCommissionAgreement::query()->where('restaurant_id', $restaurant->id)->exists()) {
            $admin = User::query()->whereHas('roles', fn ($q) => $q->where('slug', 'super_admin'))->first();
            RestaurantCommissionAgreement::query()->create([
                'restaurant_id' => $restaurant->id,
                'application_id' => null,
                'commission_type' => 'percentage',
                'commission_rate' => config('partner.default_commission_rate'),
                'fixed_fee_cents' => 0,
                'status' => 'accepted',
                'effective_from' => now()->toDateString(),
                'created_by' => $admin?->id ?? $owner->id,
                'accepted_by' => $owner->id,
                'accepted_at' => now(),
                'terms_version' => config('partner.terms_version'),
            ]);
        }

        $platformSlug = config('suvakamana.platform_restaurant_slug', 'suvakamana-restaurant');
        $platform = Restaurant::query()->where('slug', $platformSlug)->first();
        if ($platform) {
            $operatorEmail = env('SEED_PLATFORM_OPERATOR_EMAIL', 'platform-menu@example.com');
            $operator = User::query()->firstOrCreate(
                ['email' => $operatorEmail],
                [
                    'first_name' => 'Platform',
                    'last_name' => 'Menu',
                    'password' => Hash::make($password),
                    'status' => 'active',
                    'email_verified_at' => now(),
                ],
            );
            RestaurantUser::query()->updateOrCreate(
                ['restaurant_id' => $platform->id, 'user_id' => $operator->id],
                ['role_id' => $ownerRole->id, 'status' => 'active', 'joined_at' => now()],
            );
            $operator->roles()->syncWithoutDetaching([
                $ownerRole->id => ['restaurant_id' => $platform->id],
            ]);
        }
    }
}
