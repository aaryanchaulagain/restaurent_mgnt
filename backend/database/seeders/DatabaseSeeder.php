<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRolesAndPermissions();
        $this->seedUsers();
        $this->call(PartnerApplicationSeeder::class);
        $this->call(CuisineSeeder::class);
        $this->call(AllergenSeeder::class);
        $this->call(RestaurantPhase4Seeder::class);
        $this->call(SuvakamanaRestaurantSeeder::class);
        $this->call(Phase5ASeeder::class);
        $this->call(Phase5BSeeder::class);
        $this->call(Phase6PaymentSeeder::class);
        $this->call(SoldRestaurantSeeder::class);
    }

    private function seedRolesAndPermissions(): void
    {
        foreach (config('suvakamana.permissions') as $slug) {
            Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => Str::headline($slug)],
            );
        }

        $roleMap = [
            'customer' => [
                'view_customer_profile',
                'manage_customer_profile',
                'submit_restaurant_application',
                'view_own_restaurant_application',
                'edit_own_restaurant_application',
                'withdraw_own_restaurant_application',
                'view_cart',
                'manage_cart',
                'manage_own_addresses',
                'prepare_checkout',
                'place_order',
                'view_own_orders',
                'cancel_own_order',
                'view_own_order_payment',
                'retry_own_payment',
            ],
            'restaurant_owner' => [
                'view_restaurant_dashboard',
                'manage_restaurant_profile',
                'view_restaurant_profile',
                'manage_restaurant_media',
                'manage_restaurant_hours',
                'manage_restaurant_service_areas',
                'activate_restaurant',
                'temporarily_close_restaurant',
                'manage_restaurant_staff',
                'manage_menu',
                'view_menu',
                'manage_menu_categories',
                'manage_menu_items',
                'manage_menu_variants',
                'manage_menu_modifiers',
                'manage_menu_allergens',
                'manage_menu_availability',
                'manage_offers',
                'manage_restaurant_offers',
                'view_orders',
                'manage_orders',
                'view_restaurant_orders',
                'accept_restaurant_orders',
                'reject_restaurant_orders',
                'prepare_restaurant_orders',
                'complete_restaurant_orders',
                'cancel_restaurant_orders',
                'view_finance',
                'view_settlements',
                'manage_restaurant_settings',
                'view_own_restaurant_application',
                'view_restaurant_payment_summaries',
                'request_restaurant_refund',
                'manage_payment_accounts',
            ],
            'restaurant_manager' => [
                'view_restaurant_dashboard',
                'manage_restaurant_profile',
                'view_restaurant_profile',
                'manage_restaurant_media',
                'manage_restaurant_hours',
                'manage_restaurant_service_areas',
                'temporarily_close_restaurant',
                'manage_menu',
                'view_menu',
                'manage_menu_categories',
                'manage_menu_items',
                'manage_menu_variants',
                'manage_menu_modifiers',
                'manage_menu_allergens',
                'manage_menu_availability',
                'manage_offers',
                'manage_restaurant_offers',
                'view_orders',
                'manage_orders',
                'view_restaurant_orders',
                'accept_restaurant_orders',
                'reject_restaurant_orders',
                'prepare_restaurant_orders',
                'complete_restaurant_orders',
                'cancel_restaurant_orders',
                'view_finance',
                'view_settlements',
                'manage_restaurant_settings',
                'view_restaurant_payment_summaries',
            ],
            'restaurant_staff' => [
                'view_restaurant_dashboard',
                'view_menu',
                'manage_menu_availability',
                'view_orders',
                'manage_orders',
                'view_restaurant_orders',
                'prepare_restaurant_orders',
            ],
            'super_admin' => [
                'view_super_admin_dashboard',
                'manage_restaurants',
                'manage_applications',
                'view_restaurant_applications',
                'review_restaurant_applications',
                'request_application_changes',
                'approve_restaurant_applications',
                'reject_restaurant_applications',
                'verify_restaurant_documents',
                'manage_commission_agreements',
                'assign_application_reviewers',
                'manage_commissions',
                'manage_settlements',
                'manage_platform_settings',
                'view_audit_logs',
                'manage_support',
                'view_all_platform_orders',
                'view_platform_order_details',
                'manage_order_exceptions',
                'view_all_platform_payments',
                'view_platform_payment_details',
                'create_full_refund',
                'create_partial_refund',
                'view_payment_disputes',
                'retry_failed_webhook',
                'manage_payment_accounts',
            ],
        ];

        foreach ($roleMap as $slug => $permissionSlugs) {
            $role = Role::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => Str::headline($slug), 'guard' => 'web'],
            );
            $ids = Permission::query()->whereIn('slug', $permissionSlugs)->pluck('id');
            $role->permissions()->sync($ids);
        }
    }

    private function seedUsers(): void
    {
        $customer = $this->makeUser(
            $this->seedCredential('SEED_CUSTOMER_EMAIL', 'customer@example.com'),
            $this->seedPassword('SEED_CUSTOMER_PASSWORD'),
            'Anisha',
            'Rai',
            'customer',
        );

        $owner = $this->makeUser(
            $this->seedCredential('SEED_RESTAURANT_OWNER_EMAIL', 'owner@example.com'),
            $this->seedPassword('SEED_RESTAURANT_OWNER_PASSWORD'),
            'Hari',
            'Thapa',
            'restaurant_owner',
        );

        $manager = $this->makeUser(
            $this->seedCredential('SEED_RESTAURANT_MANAGER_EMAIL', 'manager@example.com'),
            $this->seedPassword('SEED_RESTAURANT_MANAGER_PASSWORD'),
            'Sita',
            'Lama',
            'restaurant_manager',
        );

        $staff = $this->makeUser(
            $this->seedCredential('SEED_RESTAURANT_STAFF_EMAIL', 'staff@example.com'),
            $this->seedPassword('SEED_RESTAURANT_STAFF_PASSWORD'),
            'Nabin',
            'KC',
            'restaurant_staff',
        );

        $admin = $this->makeUser(
            $this->seedCredential('SEED_SUPER_ADMIN_EMAIL', 'admin@example.com'),
            $this->seedPassword('SEED_SUPER_ADMIN_PASSWORD'),
            'Suvakamana',
            'Admin',
            'super_admin',
        );

        $restaurant = Restaurant::query()->firstOrCreate(
            ['slug' => 'himalayan-kitchen'],
            [
                'public_id' => (string) Str::uuid(),
                'legal_business_name' => 'Himalayan Kitchen Pty Ltd',
                'trading_name' => 'Himalayan Kitchen',
                'status' => 'active',
                'verification_status' => 'verified',
                'timezone' => config('partner.default_timezone'),
                'currency' => config('partner.default_currency'),
                'approved_at' => now(),
            ],
        );

        foreach ([
            [$owner, 'restaurant_owner'],
            [$manager, 'restaurant_manager'],
            [$staff, 'restaurant_staff'],
        ] as [$user, $roleSlug]) {
            $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
            RestaurantUser::query()->updateOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'user_id' => $user->id,
                ],
                [
                    'role_id' => $role->id,
                    'status' => 'active',
                    'joined_at' => now(),
                ],
            );
            $user->roles()->syncWithoutDetaching([
                $role->id => ['restaurant_id' => $restaurant->id],
            ]);
        }

        unset($customer, $admin);
    }

    private function seedCredential(string $envKey, string $localDefault): string
    {
        $value = env($envKey);
        if (filled($value)) {
            return (string) $value;
        }

        if (app()->environment('production')) {
            throw new RuntimeException("Missing required seed credential {$envKey} for production.");
        }

        return $localDefault;
    }

    private function seedPassword(string $envKey): string
    {
        $value = env($envKey);
        if (filled($value)) {
            return (string) $value;
        }

        if (app()->environment('production')) {
            throw new RuntimeException("Missing required seed password {$envKey} for production.");
        }

        return 'Password1!';
    }

    private function makeUser(
        string $email,
        string $password,
        string $first,
        string $last,
        string $roleSlug,
    ): User {
        $user = User::query()->updateOrCreate(
            ['email' => Str::lower($email)],
            [
                'first_name' => $first,
                'last_name' => $last,
                'password' => Hash::make($password),
                'status' => 'active',
                'email_verified_at' => now(),
            ],
        );

        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        if (! $user->roles()->where('roles.id', $role->id)->exists()) {
            $user->roles()->attach($role->id, ['restaurant_id' => null]);
        }

        return $user;
    }
}
