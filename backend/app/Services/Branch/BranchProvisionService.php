<?php

namespace App\Services\Branch;

use App\Enums\Partner\RestaurantStatus;
use App\Events\Branch\BranchCreated;
use App\Events\Branch\BranchStaffAssigned;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Restaurant;
use App\Models\RestaurantAddress;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Services\Business\LegacyRestaurantRoleSynchronizer;
use App\Support\BranchStatuses;
use App\Support\BusinessRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Field ownership (Phase 2):
 * - Branch owns: name, code, status, email, phone, timezone, accepting_orders,
 *   delivery_radius_km, minimum_order_amount_cents, geo snapshot fields.
 * - Restaurant (legacy) owns: operational menus/orders/hours/payments; address rows
 *   live in restaurant_addresses. Branch create/update mirrors contact/geo/accepting
 *   onto the linked restaurant and primary address so modules stay consistent.
 */
class BranchProvisionService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly LegacyRestaurantRoleSynchronizer $legacySync,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{branch: Branch, restaurant: Restaurant}
     */
    public function create(Business $business, array $data, User $actor, ?Request $request = null): array
    {
        return DB::transaction(function () use ($business, $data, $actor, $request) {
            $status = $data['status'] ?? BranchStatuses::DRAFT;
            if (! in_array($status, BranchStatuses::ownerAssignable(), true)) {
                throw ValidationException::withMessages(['status' => ['Invalid initial branch status.']]);
            }

            $code = $this->normalizeCode($data['code'] ?? null, $data['name']);
            $this->assertUniqueCode($business, $code);

            $branch = Branch::query()->create([
                'public_id' => (string) Str::uuid(),
                'business_id' => $business->id,
                'restaurant_id' => null,
                'name' => $data['name'],
                'code' => $code,
                'email' => $data['email'] ?? $business->email,
                'phone' => $data['phone'] ?? $business->phone,
                'address_line' => $data['address_line'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'postcode' => $data['postcode'] ?? null,
                'country' => $data['country'] ?? 'AU',
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'delivery_radius_km' => $data['delivery_radius_km'] ?? null,
                'minimum_order_amount_cents' => (int) ($data['minimum_order_amount_cents'] ?? 0),
                'accepting_orders' => false,
                'is_default' => ! $business->branches()->exists(),
                'status' => $status,
                'timezone' => $data['timezone'] ?? config('partner.default_timezone'),
                'sort_order' => (int) ($business->branches()->max('sort_order') ?? 0) + 1,
            ]);

            $restaurant = $this->createLinkedRestaurant($business, $branch, $data);
            $branch->forceFill(['restaurant_id' => $restaurant->id])->save();
            $restaurant->forceFill([
                'business_id' => $business->id,
                'branch_id' => $branch->id,
            ])->save();

            $this->syncAddress($restaurant, $branch, $data);

            $this->ensureCreatorBranchAccess($business, $branch, $actor, $request);
            $this->syncBusinessManagersToBranch($business, $branch, $actor, $request);

            $this->auditLogger->log(
                'branch.created',
                $actor,
                $branch,
                restaurantId: $restaurant->id,
                metadata: [
                    'business_id' => $business->id,
                    'branch_id' => $branch->id,
                    'status' => $branch->status,
                ],
                request: $request,
            );

            event(new BranchCreated($branch->fresh(['restaurant', 'business']), $actor));

            return [
                'branch' => $branch->fresh(['restaurant', 'business', 'branchUsers']),
                'restaurant' => $restaurant->fresh(),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Branch $branch, array $data, User $actor, ?Request $request = null): Branch
    {
        return DB::transaction(function () use ($branch, $data, $actor, $request) {
            $old = $branch->only([
                'name', 'code', 'email', 'phone', 'address_line', 'city', 'state', 'postcode',
                'country', 'latitude', 'longitude', 'delivery_radius_km', 'minimum_order_amount_cents',
                'accepting_orders', 'timezone',
            ]);

            if (array_key_exists('code', $data) && $data['code'] !== null) {
                $code = $this->normalizeCode($data['code'], $data['name'] ?? $branch->name);
                $this->assertUniqueCode($branch->business, $code, $branch->id);
                $data['code'] = $code;
            }

            $branch->fill(collect($data)->only([
                'name', 'code', 'email', 'phone', 'address_line', 'city', 'state', 'postcode',
                'country', 'latitude', 'longitude', 'delivery_radius_km', 'minimum_order_amount_cents',
                'accepting_orders', 'timezone',
            ])->all());
            $branch->save();

            $restaurant = $branch->restaurant;
            if ($restaurant) {
                $restaurant->forceFill([
                    'business_email' => $branch->email ?? $restaurant->business_email,
                    'business_phone' => $branch->phone ?? $restaurant->business_phone,
                    'timezone' => $branch->timezone ?? $restaurant->timezone,
                    'minimum_order_cents' => $branch->minimum_order_amount_cents,
                    'accepting_orders' => (bool) $branch->accepting_orders,
                ])->save();
                $this->syncAddress($restaurant, $branch, $data + [
                    'address_line' => $branch->address_line,
                    'city' => $branch->city,
                    'state' => $branch->state,
                    'postcode' => $branch->postcode,
                    'country' => $branch->country,
                    'latitude' => $branch->latitude,
                    'longitude' => $branch->longitude,
                ]);
            }

            $this->auditLogger->log(
                'branch.updated',
                $actor,
                $branch,
                oldValues: $old,
                newValues: $branch->only(array_keys($old)),
                restaurantId: $restaurant?->id,
                metadata: ['business_id' => $branch->business_id, 'branch_id' => $branch->id],
                request: $request,
            );

            event(new \App\Events\Branch\BranchUpdated($branch, $old, $branch->only(array_keys($old)), $actor));

            return $branch->fresh(['restaurant', 'business']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createLinkedRestaurant(Business $business, Branch $branch, array $data): Restaurant
    {
        $baseSlug = Str::slug($business->slug.'-'.$branch->code);
        $slug = $this->uniqueRestaurantSlug($baseSlug);

        $restaurantStatus = match ($branch->status) {
            BranchStatuses::ACTIVE => RestaurantStatus::Active,
            BranchStatuses::PAUSED => RestaurantStatus::TemporarilyClosed,
            BranchStatuses::SUSPENDED, BranchStatuses::INACTIVE => RestaurantStatus::Disabled,
            default => RestaurantStatus::PendingSetup,
        };

        return Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'slug' => $slug,
            'legal_business_name' => $business->name,
            'trading_name' => $branch->name,
            'short_description' => $business->description,
            'description' => $business->description,
            'business_email' => $branch->email,
            'business_phone' => $branch->phone,
            'status' => $restaurantStatus,
            'verification_status' => 'verified',
            'timezone' => $branch->timezone ?? config('partner.default_timezone'),
            'currency' => config('partner.default_currency'),
            'ownership_type' => $business->ownership_type ?: 'third_party',
            'vendor_type' => match ($business->business_type) {
                'bakery' => 'bakery',
                'grocery' => 'grocery',
                'butcher' => 'butchery',
                default => 'restaurant',
            },
            'minimum_order_cents' => $branch->minimum_order_amount_cents,
            'accepting_orders' => false,
            'pickup_enabled' => true,
            'published_at' => $branch->status === BranchStatuses::ACTIVE ? now() : null,
            'approved_at' => now(),
            'business_id' => $business->id,
            'branch_id' => $branch->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncAddress(Restaurant $restaurant, Branch $branch, array $data): void
    {
        $line = $data['address_line'] ?? $branch->address_line;
        if (! $line && ! ($data['city'] ?? $branch->city)) {
            return;
        }

        RestaurantAddress::query()->updateOrCreate(
            [
                'restaurant_id' => $restaurant->id,
                'is_primary' => true,
            ],
            [
                'address_type' => 'physical',
                'address_line_1' => $line ?: 'Address pending',
                'suburb' => $data['city'] ?? $branch->city ?? 'Unknown',
                'state' => $data['state'] ?? $branch->state ?? 'N/A',
                'postcode' => $data['postcode'] ?? $branch->postcode ?? '0000',
                'country' => $data['country'] ?? $branch->country ?? 'AU',
                'latitude' => $data['latitude'] ?? $branch->latitude,
                'longitude' => $data['longitude'] ?? $branch->longitude,
                'is_primary' => true,
            ]
        );
    }

    private function ensureCreatorBranchAccess(Business $business, Branch $branch, User $actor, ?Request $request): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        $isBusinessManager = BusinessUser::query()
            ->where('business_id', $business->id)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->whereIn('role', BusinessRoles::businessManagers())
            ->exists();

        if ($isBusinessManager) {
            return;
        }

        BranchUser::query()->firstOrCreate(
            [
                'branch_id' => $branch->id,
                'user_id' => $actor->id,
                'role' => BusinessRoles::BRANCH_MANAGER,
            ],
            [
                'status' => 'active',
                'invited_by' => $actor->id,
                'joined_at' => now(),
            ]
        );

        $this->legacySync->syncBranchAssignment(
            $branch,
            $actor,
            BusinessRoles::BRANCH_MANAGER,
            $actor,
            $request,
        );

        event(new BranchStaffAssigned($branch, $actor, BusinessRoles::BRANCH_MANAGER, $actor));
    }

    private function syncBusinessManagersToBranch(Business $business, Branch $branch, User $actor, ?Request $request): void
    {
        $managers = BusinessUser::query()
            ->where('business_id', $business->id)
            ->where('status', 'active')
            ->whereIn('role', BusinessRoles::businessManagers())
            ->get();

        foreach ($managers as $membership) {
            $user = User::query()->find($membership->user_id);
            if (! $user) {
                continue;
            }
            $this->legacySync->syncBranchAssignment(
                $branch,
                $user,
                $membership->role,
                $actor,
                $request,
            );
        }
    }

    private function normalizeCode(?string $code, string $name): string
    {
        $raw = $code !== null && trim($code) !== ''
            ? $code
            : $name;
        $normalized = Str::upper(Str::slug($raw, '_'));
        $normalized = preg_replace('/[^A-Z0-9_]/', '', $normalized) ?: 'BRANCH';

        return Str::limit($normalized, 64, '');
    }

    private function assertUniqueCode(Business $business, string $code, ?int $ignoreBranchId = null): void
    {
        $query = Branch::query()
            ->where('business_id', $business->id)
            ->where('code', $code);
        if ($ignoreBranchId) {
            $query->where('id', '!=', $ignoreBranchId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'code' => ['A branch with this code already exists for this business.'],
            ]);
        }
    }

    private function uniqueRestaurantSlug(string $base): string
    {
        $slug = $base ?: 'branch';
        $candidate = $slug;
        $i = 2;
        while (Restaurant::query()->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$i;
            $i++;
        }

        return $candidate;
    }
}
