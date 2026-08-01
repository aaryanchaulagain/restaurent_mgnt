<?php

namespace App\Services\Restaurant;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantCommissionAgreement;
use App\Models\RestaurantUser;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Services\Business\BusinessHierarchyMigrator;
use App\Support\VendorTypes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RestaurantProvisionService
{
    public function __construct(
        private readonly RestaurantMembershipService $membership,
        private readonly AuditLogger $auditLogger,
        private readonly BusinessHierarchyMigrator $businessHierarchyMigrator,
    ) {}

    /**
     * @param  array{
     *   trading_name: string,
     *   legal_business_name: string,
     *   business_email?: string|null,
     *   business_phone?: string|null,
     *   description?: string|null,
     *   ownership_type?: string,
     *   activate_now?: bool,
     *   commission_rate?: float|string|null,
     *   owner: array{first_name: string, last_name: string, email: string, password?: string|null, phone?: string|null}
     * }  $data
     * @return array{restaurant: Restaurant, owner: User, temporary_password: string|null}
     */
    public function provision(array $data, User $admin, ?Request $request = null): array
    {
        return DB::transaction(function () use ($data, $admin, $request) {
            $ownerData = $data['owner'];
            $email = strtolower(trim($ownerData['email']));

            $existing = User::query()->where('email', $email)->first();
            $temporaryPassword = null;

            if ($existing) {
                if ($existing->isSuperAdmin()) {
                    throw ValidationException::withMessages([
                        'owner.email' => ['That email belongs to a platform super admin.'],
                    ]);
                }
                $owner = $existing;
                if (! empty($ownerData['password'])) {
                    $owner->forceFill(['password' => $ownerData['password']])->save();
                }
            } else {
                $temporaryPassword = $ownerData['password'] ?? Str::password(12);
                $owner = User::query()->create([
                    'first_name' => $ownerData['first_name'],
                    'last_name' => $ownerData['last_name'],
                    'email' => $email,
                    'phone' => $ownerData['phone'] ?? null,
                    'password' => $temporaryPassword,
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]);
            }

            $activateNow = (bool) ($data['activate_now'] ?? false);
            $ownershipType = $data['ownership_type'] ?? 'third_party';
            if (! in_array($ownershipType, ['first_party', 'third_party'], true)) {
                $ownershipType = 'third_party';
            }

            $vendorType = $data['vendor_type'] ?? VendorTypes::RESTAURANT;
            if (! in_array($vendorType, VendorTypes::all(), true)) {
                $vendorType = VendorTypes::RESTAURANT;
            }

            $slug = $this->uniqueSlug(Str::slug($data['trading_name'] ?: $data['legal_business_name'] ?: $vendorType));

            $restaurant = Restaurant::query()->create([
                'public_id' => (string) Str::uuid(),
                'slug' => $slug,
                'legal_business_name' => $data['legal_business_name'],
                'trading_name' => $data['trading_name'],
                'description' => $data['description'] ?? null,
                'business_email' => $data['business_email'] ?? $email,
                'business_phone' => $data['business_phone'] ?? ($ownerData['phone'] ?? null),
                'status' => $activateNow ? RestaurantStatus::Active : RestaurantStatus::PendingSetup,
                'verification_status' => 'verified',
                'timezone' => config('partner.default_timezone'),
                'currency' => config('partner.default_currency'),
                'ownership_type' => $ownershipType,
                'vendor_type' => $vendorType,
                'approved_at' => now(),
                'approved_by' => $admin->id,
                'published_at' => $activateNow ? now() : null,
                'accepting_orders' => $activateNow,
                'pickup_enabled' => true,
            ]);

            $this->membership->assign($restaurant, $owner, 'restaurant_owner', $admin, $request);

            $rate = $data['commission_rate'] ?? config('partner.default_commission_rate');
            RestaurantCommissionAgreement::query()->create([
                'restaurant_id' => $restaurant->id,
                'application_id' => null,
                'commission_type' => 'percentage',
                'commission_rate' => $rate,
                'fixed_fee_cents' => 0,
                'status' => 'accepted',
                'effective_from' => now()->toDateString(),
                'created_by' => $admin->id,
                'accepted_by' => $owner->id,
                'accepted_at' => now(),
                'terms_version' => config('partner.terms_version'),
            ]);

            $hierarchy = $this->businessHierarchyMigrator->migrateRestaurant($restaurant->fresh());

            $this->auditLogger->log(
                'admin.restaurant_provisioned',
                $admin,
                $restaurant,
                restaurantId: $restaurant->id,
                metadata: [
                    'owner_user_id' => $owner->id,
                    'activate_now' => $activateNow,
                    'ownership_type' => $ownershipType,
                    'business_id' => $hierarchy['business']->id,
                    'branch_id' => $hierarchy['branch']->id,
                ],
                request: $request,
            );

            return [
                'restaurant' => $restaurant->fresh(['commissionAgreements', 'restaurantUsers.user', 'restaurantUsers.role', 'business', 'branch']),
                'owner' => $owner->fresh(),
                'temporary_password' => $existing ? null : $temporaryPassword,
                'business' => $hierarchy['business'],
                'branch' => $hierarchy['branch'],
            ];
        });
    }

    public function updateRestaurant(Restaurant $restaurant, array $data, User $admin, ?Request $request = null): Restaurant
    {
        $old = $restaurant->only([
            'trading_name', 'legal_business_name', 'business_email', 'business_phone',
            'description', 'status', 'accepting_orders', 'suspension_reason',
        ]);

        if (isset($data['status'])) {
            $status = RestaurantStatus::tryFrom((string) $data['status']);
            if (! $status) {
                throw ValidationException::withMessages(['status' => ['Invalid status.']]);
            }
            $restaurant->status = $status;

            if ($status === RestaurantStatus::Suspended) {
                $restaurant->suspended_at = now();
                $restaurant->suspension_reason = $data['suspension_reason'] ?? $restaurant->suspension_reason;
                $restaurant->accepting_orders = false;
            } elseif ($status === RestaurantStatus::Active) {
                $restaurant->suspended_at = null;
                $restaurant->suspension_reason = null;
                if (! $restaurant->published_at) {
                    $restaurant->published_at = now();
                }
                $restaurant->accepting_orders = $data['accepting_orders'] ?? true;
            } elseif ($status === RestaurantStatus::TemporarilyClosed) {
                $restaurant->accepting_orders = false;
                $restaurant->temporarily_closed_reason = $data['temporarily_closed_reason'] ?? $restaurant->temporarily_closed_reason;
            } elseif ($status === RestaurantStatus::Disabled) {
                $restaurant->accepting_orders = false;
            }
        }

        foreach (['trading_name', 'legal_business_name', 'business_email', 'business_phone', 'description', 'short_description'] as $field) {
            if (array_key_exists($field, $data)) {
                $restaurant->{$field} = $data[$field];
            }
        }

        if (array_key_exists('accepting_orders', $data) && ! isset($data['status'])) {
            $restaurant->accepting_orders = (bool) $data['accepting_orders'];
        }

        $restaurant->save();

        $this->auditLogger->log(
            'admin.restaurant_updated',
            $admin,
            $restaurant,
            oldValues: $old,
            newValues: $restaurant->only(array_keys($old)),
            restaurantId: $restaurant->id,
            request: $request,
        );

        return $restaurant->fresh();
    }

    public function softRemove(Restaurant $restaurant, User $admin, ?Request $request = null): Restaurant
    {
        $hasOrders = Order::query()->where('restaurant_id', $restaurant->id)->exists();

        return DB::transaction(function () use ($restaurant, $admin, $request, $hasOrders) {
            RestaurantUser::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('status', '!=', 'removed')
                ->update(['status' => 'removed']);

            $restaurant->status = RestaurantStatus::Disabled;
            $restaurant->accepting_orders = false;
            $restaurant->suspended_at = now();
            $restaurant->suspension_reason = $restaurant->suspension_reason ?: 'Removed by platform admin';
            $restaurant->save();

            if (! $hasOrders) {
                $restaurant->delete();
            }

            $this->auditLogger->log(
                'admin.restaurant_removed',
                $admin,
                $restaurant,
                restaurantId: $restaurant->id,
                metadata: ['hard_deleted' => ! $hasOrders && $restaurant->trashed()],
                request: $request,
            );

            return $restaurant;
        });
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base ?: 'restaurant';
        $candidate = $slug;
        $i = 1;
        while (Restaurant::withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$i;
            $i++;
        }

        return $candidate;
    }
}
