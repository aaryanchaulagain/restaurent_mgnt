<?php

namespace App\Services\Business;

use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Restaurant;
use App\Models\RestaurantAddress;
use App\Models\RestaurantUser;
use App\Support\BranchStatuses;
use App\Support\BusinessRoles;
use App\Support\BusinessTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BusinessHierarchyMigrator
{
    /**
     * Migrate every restaurant that is not yet linked into one business + one default branch.
     *
     * @return array{migrated: int, skipped: int}
     */
    public function migrateAll(): array
    {
        $migrated = 0;
        $skipped = 0;

        Restaurant::query()
            ->withTrashed()
            ->orderBy('id')
            ->chunkById(50, function ($restaurants) use (&$migrated, &$skipped) {
                foreach ($restaurants as $restaurant) {
                    if ($restaurant->business_id && $restaurant->branch_id) {
                        $skipped++;
                        continue;
                    }
                    $this->migrateRestaurant($restaurant);
                    $migrated++;
                }
            });

        return compact('migrated', 'skipped');
    }

    public function migrateRestaurant(Restaurant $restaurant): array
    {
        return DB::transaction(function () use ($restaurant) {
            $restaurant->refresh();
            if ($restaurant->business_id && $restaurant->branch_id) {
                return [
                    'business' => Business::query()->find($restaurant->business_id),
                    'branch' => Branch::query()->find($restaurant->branch_id),
                ];
            }

            $ownerMembership = RestaurantUser::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('status', 'active')
                ->whereHas('role', fn ($q) => $q->where('slug', 'restaurant_owner'))
                ->orderBy('id')
                ->first();

            $ownerUserId = $ownerMembership?->user_id;

            $slugBase = $restaurant->slug ?: Str::slug($restaurant->trading_name ?: 'business');
            $businessSlug = $this->uniqueBusinessSlug($slugBase);

            $business = Business::query()->create([
                'public_id' => (string) Str::uuid(),
                'owner_user_id' => $ownerUserId,
                'name' => $restaurant->trading_name ?: ($restaurant->legal_business_name ?: 'Business'),
                'slug' => $businessSlug,
                'business_type' => BusinessTypes::fromVendorType($restaurant->vendor_type ?? null),
                'ownership_type' => $restaurant->ownership_type ?: 'third_party',
                'logo_path' => $restaurant->logo_path,
                'description' => $restaurant->description ?? $restaurant->short_description,
                'email' => $restaurant->business_email,
                'phone' => $restaurant->business_phone,
                'status' => $this->mapRestaurantStatusToBusiness($restaurant),
                'suspended_at' => $restaurant->suspended_at,
                'suspension_reason' => $restaurant->suspension_reason,
            ]);

            $address = RestaurantAddress::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('is_primary', true)
                ->first()
                ?? RestaurantAddress::query()->where('restaurant_id', $restaurant->id)->first();

            $branch = Branch::query()->create([
                'public_id' => (string) Str::uuid(),
                'business_id' => $business->id,
                'restaurant_id' => $restaurant->id,
                'name' => ($restaurant->trading_name ?: 'Main').' — Main',
                'code' => 'MAIN',
                'email' => $restaurant->business_email,
                'phone' => $restaurant->business_phone,
                'address_line' => $address?->address_line_1,
                'city' => $address?->suburb,
                'state' => $address?->state,
                'postcode' => $address?->postcode,
                'country' => $address?->country ?? 'AU',
                'latitude' => $address?->latitude,
                'longitude' => $address?->longitude,
                'minimum_order_amount_cents' => (int) ($restaurant->minimum_order_cents ?? 0),
                'accepting_orders' => (bool) $restaurant->accepting_orders,
                'is_default' => true,
                'status' => $this->mapRestaurantStatusToBranch($restaurant),
                'timezone' => $restaurant->timezone,
                'sort_order' => 0,
                'suspended_at' => $restaurant->suspended_at,
            ]);

            $restaurant->forceFill([
                'business_id' => $business->id,
                'branch_id' => $branch->id,
            ])->save();

            $this->migrateStaff($restaurant, $business, $branch);

            return compact('business', 'branch');
        });
    }

    private function migrateStaff(Restaurant $restaurant, Business $business, Branch $branch): void
    {
        $memberships = RestaurantUser::query()
            ->where('restaurant_id', $restaurant->id)
            ->with('role')
            ->get();

        foreach ($memberships as $membership) {
            $map = BusinessRoles::fromRestaurantRole($membership->role?->slug);
            $status = $membership->status === 'active' ? 'active' : ($membership->status ?: 'active');

            if (! empty($map['business'])) {
                BusinessUser::query()->firstOrCreate(
                    [
                        'business_id' => $business->id,
                        'user_id' => $membership->user_id,
                        'role' => $map['business'],
                    ],
                    [
                        'status' => $status,
                        'invited_by' => $membership->invited_by,
                        'joined_at' => $membership->joined_at ?? now(),
                    ]
                );
            }

            if (! empty($map['branch'])) {
                BranchUser::query()->firstOrCreate(
                    [
                        'branch_id' => $branch->id,
                        'user_id' => $membership->user_id,
                        'role' => $map['branch'],
                    ],
                    [
                        'status' => $status,
                        'invited_by' => $membership->invited_by,
                        'joined_at' => $membership->joined_at ?? now(),
                    ]
                );
            }
        }
    }

    private function uniqueBusinessSlug(string $base): string
    {
        $slug = $base ?: 'business';
        $candidate = $slug;
        $i = 2;
        while (Business::query()->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$i;
            $i++;
        }

        return $candidate;
    }

    private function mapRestaurantStatusToBusiness(Restaurant $restaurant): string
    {
        $status = $restaurant->status?->value ?? (string) $restaurant->status;

        return match ($status) {
            'suspended', 'disabled' => 'suspended',
            'pending_setup' => 'pending',
            default => 'active',
        };
    }

    private function mapRestaurantStatusToBranch(Restaurant $restaurant): string
    {
        $status = $restaurant->status?->value ?? (string) $restaurant->status;

        return match ($status) {
            'suspended', 'disabled' => BranchStatuses::SUSPENDED,
            'temporarily_closed' => BranchStatuses::PAUSED,
            'pending_setup' => BranchStatuses::DRAFT,
            default => BranchStatuses::ACTIVE,
        };
    }
}
