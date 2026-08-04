<?php

namespace App\Services\Location;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Branch;
use App\Models\Business;
use App\Models\CustomerAddress;
use App\Models\User;
use App\Services\PublicCatalog\PublicBusinessBranchService;
use App\Services\Restaurant\RestaurantOpenStatusService;
use App\Services\Restaurant\ServiceAreaValidationService;
use App\Support\BranchStatuses;
use App\Support\BusinessTypes;
use App\Support\GeoDistance;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Location-aware branch eligibility and nearest eligible recommendation.
 * Does not modify carts, orders, or inventory.
 */
class BranchRecommendationService
{
    public function __construct(
        private readonly PublicBusinessBranchService $publicBranches,
        private readonly ServiceAreaValidationService $serviceAreas,
        private readonly RestaurantOpenStatusService $openStatus,
    ) {}

    /**
     * @param  array{
     *   fulfilment?: string,
     *   postcode?: string|null,
     *   city?: string|null,
     *   suburb?: string|null,
     *   state?: string|null,
     *   country?: string|null,
     *   latitude?: float|null,
     *   longitude?: float|null,
     *   address_public_id?: string|null
     * }  $input
     * @return array<string, mixed>
     */
    public function recommend(string $businessSlug, array $input, ?User $customer = null): array
    {
        $fulfilment = $this->normalizeFulfilment($input['fulfilment'] ?? 'delivery');
        $location = $this->resolveLocation($input, $customer);

        if ($fulfilment === 'delivery') {
            $this->assertDeliveryLocationPresent($location);
        }

        try {
            $business = $this->publicBranches->resolveBusiness($businessSlug);
        } catch (NotFoundHttpException) {
            throw ValidationException::withMessages([
                'code' => ['BUSINESS_NOT_AVAILABLE'],
                'business' => ['This business is unavailable.'],
            ]);
        }

        $branches = $this->publicBranches->listPublicBranches($business);
        if ($branches->isEmpty()) {
            throw ValidationException::withMessages([
                'code' => ['NO_PUBLIC_BRANCHES'],
                'business' => ['This business has no public locations.'],
            ]);
        }

        $evaluated = [];
        foreach ($branches as $branch) {
            $evaluated[] = $this->evaluateBranch($branch, $fulfilment, $location);
        }

        $sorted = $this->sortRecommendations($evaluated, $fulfilment);
        $recommendedId = $this->markRecommended($sorted, $fulfilment);

        return [
            'business' => [
                'public_id' => $business->public_id,
                'slug' => $business->slug,
                'name' => $business->name,
                'business_type' => BusinessTypes::normalize($business->business_type),
            ],
            'fulfilment' => $fulfilment,
            'location' => [
                'postcode' => $location['postcode'],
                'city' => $location['city'],
                'state' => $location['state'],
                'country' => $location['country'],
                'coordinates_used' => $location['latitude'] !== null && $location['longitude'] !== null,
                'source' => $location['source'],
            ],
            'recommended_branch_public_id' => $recommendedId,
            'branches' => $sorted,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *   postcode: ?string,
     *   city: ?string,
     *   state: ?string,
     *   country: ?string,
     *   latitude: ?float,
     *   longitude: ?float,
     *   source: string
     * }
     */
    private function resolveLocation(array $input, ?User $customer): array
    {
        $addressId = $input['address_public_id'] ?? null;
        if ($addressId) {
            if (! $customer) {
                throw new AccessDeniedHttpException('Authentication required to use a saved address.');
            }

            $address = CustomerAddress::query()
                ->where('public_id', $addressId)
                ->where('customer_id', $customer->id)
                ->first();

            if (! $address) {
                // Ownership miss: do not reveal whether the ID exists for another customer.
                throw new NotFoundHttpException('Address not found.');
            }

            $lat = $address->latitude !== null ? (float) $address->latitude : null;
            $lng = $address->longitude !== null ? (float) $address->longitude : null;

            return [
                'postcode' => $address->postcode ? (string) $address->postcode : null,
                'city' => $address->suburb ? (string) $address->suburb : null,
                'state' => $address->state ? (string) $address->state : null,
                'country' => $address->country ? (string) $address->country : 'AU',
                'latitude' => $lat,
                'longitude' => $lng,
                'source' => 'saved_address',
            ];
        }

        $lat = array_key_exists('latitude', $input) && $input['latitude'] !== null
            ? (float) $input['latitude']
            : null;
        $lng = array_key_exists('longitude', $input) && $input['longitude'] !== null
            ? (float) $input['longitude']
            : null;

        if ($lat !== null && ! GeoDistance::isValidLatitude($lat)) {
            throw ValidationException::withMessages([
                'code' => ['LOCATION_INVALID'],
                'latitude' => ['Latitude must be between -90 and 90.'],
            ]);
        }
        if ($lng !== null && ! GeoDistance::isValidLongitude($lng)) {
            throw ValidationException::withMessages([
                'code' => ['LOCATION_INVALID'],
                'longitude' => ['Longitude must be between -180 and 180.'],
            ]);
        }
        if (($lat === null) xor ($lng === null)) {
            throw ValidationException::withMessages([
                'code' => ['LOCATION_INVALID'],
                'location' => ['Latitude and longitude must be provided together.'],
            ]);
        }

        $postcode = isset($input['postcode']) ? trim((string) $input['postcode']) : null;
        $postcode = $postcode !== '' ? $postcode : null;
        $city = $input['city'] ?? $input['suburb'] ?? null;
        $city = $city !== null && trim((string) $city) !== '' ? trim((string) $city) : null;

        $source = ($lat !== null && $lng !== null) ? 'browser_or_manual_coordinates' : 'manual';

        return [
            'postcode' => $postcode,
            'city' => $city,
            'state' => isset($input['state']) && trim((string) $input['state']) !== ''
                ? trim((string) $input['state'])
                : null,
            'country' => isset($input['country']) && trim((string) $input['country']) !== ''
                ? strtoupper(trim((string) $input['country']))
                : 'AU',
            'latitude' => $lat,
            'longitude' => $lng,
            'source' => $source,
        ];
    }

    /**
     * @param  array{postcode: ?string, latitude: ?float, longitude: ?float}  $location
     */
    private function assertDeliveryLocationPresent(array $location): void
    {
        if ($location['postcode'] || ($location['latitude'] !== null && $location['longitude'] !== null)) {
            return;
        }

        throw ValidationException::withMessages([
            'code' => ['ADDRESS_POSTCODE_REQUIRED'],
            'location' => ['Enter a postcode or share your location to find delivery branches.'],
        ]);
    }

    private function normalizeFulfilment(string $fulfilment): string
    {
        $value = strtolower(trim($fulfilment));
        if (in_array($value, ['delivery', 'restaurant_delivery'], true)) {
            return 'delivery';
        }
        if ($value === 'pickup') {
            return 'pickup';
        }

        throw ValidationException::withMessages([
            'fulfilment' => ['Fulfilment must be delivery or pickup.'],
        ]);
    }

    /**
     * @param  array{postcode: ?string, latitude: ?float, longitude: ?float}  $location
     * @return array<string, mixed>
     */
    private function evaluateBranch(Branch $branch, string $fulfilment, array $location): array
    {
        $reasons = [];
        $restaurant = $branch->restaurant;

        if (! $restaurant || ! $this->publicBranches->isBranchPubliclyBrowsable($branch)) {
            return $this->payload($branch, $restaurant, $fulfilment, $location, false, false, false, false, false, [
                'BRANCH_NOT_PUBLIC',
            ], null);
        }

        if (! $this->relationshipsOk($branch, $restaurant)) {
            return $this->payload($branch, $restaurant, $fulfilment, $location, true, false, false, false, false, [
                'BRANCH_RESTAURANT_INVALID',
            ], null);
        }

        $supportsDelivery = (bool) $restaurant->restaurant_delivery_enabled;
        $supportsPickup = (bool) $restaurant->pickup_enabled;

        $status = $restaurant->status instanceof RestaurantStatus
            ? $restaurant->status
            : RestaurantStatus::tryFrom((string) $restaurant->status);

        $temporarilyClosed = $status === RestaurantStatus::TemporarilyClosed
            || ($branch->status === BranchStatuses::PAUSED)
            || ($restaurant->temporarily_closed_until && $restaurant->temporarily_closed_until->isFuture());

        $accepting = (bool) $restaurant->accepting_orders
            && (bool) $branch->accepting_orders
            && $branch->status === BranchStatuses::ACTIVE
            && $status === RestaurantStatus::Active
            && ! $temporarilyClosed;

        $isOpenNow = $accepting && $this->openStatus->isOpenNow($restaurant);
        $nextOpening = $this->openStatus->nextOpeningTime($restaurant);

        $deliveryEligible = false;
        $pickupEligible = false;

        if ($fulfilment === 'delivery') {
            if (! $supportsDelivery) {
                $reasons[] = 'DELIVERY_NOT_SUPPORTED';
            } else {
                $check = $this->serviceAreas->validateDeliveryAddress(
                    $restaurant,
                    $location['postcode'],
                    $location['latitude'],
                    $location['longitude'],
                );
                if ($check['supported']) {
                    $deliveryEligible = true;
                } else {
                    $reasons[] = match ($check['code'] ?? '') {
                        'DELIVERY_DISABLED' => 'DELIVERY_NOT_SUPPORTED',
                        'ADDRESS_COORDINATES_REQUIRED' => 'CUSTOMER_LOCATION_UNAVAILABLE',
                        default => 'OUTSIDE_SERVICE_AREA',
                    };
                }
            }

            if (! $accepting) {
                $reasons[] = 'BRANCH_NOT_ACCEPTING_ORDERS';
                $deliveryEligible = false;
            }
        } else {
            if (! $supportsPickup) {
                $reasons[] = 'PICKUP_NOT_SUPPORTED';
            } else {
                $pickupEligible = true;
            }
            if (! $accepting) {
                $reasons[] = 'BRANCH_NOT_ACCEPTING_ORDERS';
                $pickupEligible = false;
            }
        }

        $distanceKm = $this->distanceKm($branch, $restaurant, $location);

        $eligibleForRequested = $fulfilment === 'delivery' ? $deliveryEligible : $pickupEligible;

        return $this->payload(
            $branch,
            $restaurant,
            $fulfilment,
            $location,
            true,
            $supportsDelivery,
            $supportsPickup,
            $deliveryEligible,
            $pickupEligible,
            array_values(array_unique($reasons)),
            $distanceKm,
            $isOpenNow,
            $accepting,
            $temporarilyClosed,
            $nextOpening,
            $eligibleForRequested,
        );
    }

    private function relationshipsOk(Branch $branch, $restaurant): bool
    {
        return (int) $branch->restaurant_id === (int) $restaurant->id
            && (int) $restaurant->branch_id === (int) $branch->id
            && ($restaurant->business_id === null || (int) $restaurant->business_id === (int) $branch->business_id);
    }

    /**
     * @param  array{latitude: ?float, longitude: ?float}  $location
     */
    private function distanceKm(Branch $branch, $restaurant, array $location): ?float
    {
        if ($location['latitude'] === null || $location['longitude'] === null) {
            return null;
        }

        $branchLat = $branch->latitude !== null ? (float) $branch->latitude : null;
        $branchLng = $branch->longitude !== null ? (float) $branch->longitude : null;

        if ($branchLat === null || $branchLng === null) {
            $primary = $restaurant->addresses()->where('is_primary', true)->first();
            $branchLat = $primary?->latitude !== null ? (float) $primary->latitude : null;
            $branchLng = $primary?->longitude !== null ? (float) $primary->longitude : null;
        }

        if ($branchLat === null || $branchLng === null) {
            return null;
        }

        if (! GeoDistance::isValidLatitude($branchLat) || ! GeoDistance::isValidLongitude($branchLng)) {
            return null;
        }

        return GeoDistance::roundKm(
            GeoDistance::haversineKm($location['latitude'], $location['longitude'], $branchLat, $branchLng)
        );
    }

    /**
     * @param  list<string>  $reasons
     * @return array<string, mixed>
     */
    private function payload(
        Branch $branch,
        $restaurant,
        string $fulfilment,
        array $location,
        bool $isPublic,
        bool $supportsDelivery,
        bool $supportsPickup,
        bool $deliveryEligible,
        bool $pickupEligible,
        array $reasons,
        ?float $distanceKm,
        bool $isOpenNow = false,
        bool $accepting = false,
        bool $temporarilyClosed = false,
        ?string $nextOpening = null,
        bool $eligibleForRequested = false,
    ): array {
        return [
            'public_id' => $branch->public_id,
            'name' => $branch->name,
            'restaurant_slug' => $restaurant?->slug,
            'is_publicly_browsable' => $isPublic,
            'is_temporarily_closed' => $temporarilyClosed,
            'is_open_now' => $isOpenNow,
            'accepting_orders' => $accepting,
            'supports_delivery' => $supportsDelivery,
            'supports_pickup' => $supportsPickup,
            'delivery_eligible' => $deliveryEligible,
            'pickup_eligible' => $pickupEligible,
            'eligible' => $eligibleForRequested,
            'distance_km' => $distanceKm,
            'next_opening_time' => $nextOpening,
            'recommended' => false,
            'recommendation_reason' => null,
            'eligibility_reasons' => $reasons,
            'is_default' => (bool) $branch->is_default,
            'sort_order' => (int) ($branch->sort_order ?? 0),
            'address' => [
                'city' => $branch->city,
                'state' => $branch->state,
                'postcode' => $branch->postcode,
            ],
            // Internal sort helpers — stripped before response.
            '_eligible' => $eligibleForRequested,
            '_accepting' => $accepting,
            '_open' => $isOpenNow,
            '_distance' => $distanceKm,
            '_sort' => (int) ($branch->sort_order ?? 0),
            '_name' => (string) $branch->name,
            '_public_id' => (string) $branch->public_id,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortRecommendations(array $rows, string $fulfilment): array
    {
        usort($rows, function (array $a, array $b) {
            // 1. Eligible for requested fulfilment
            if ((int) $a['_eligible'] !== (int) $b['_eligible']) {
                return (int) $b['_eligible'] <=> (int) $a['_eligible'];
            }
            // 2. Accepting orders
            if ((int) $a['_accepting'] !== (int) $b['_accepting']) {
                return (int) $b['_accepting'] <=> (int) $a['_accepting'];
            }
            // 3. Open now
            if ((int) $a['_open'] !== (int) $b['_open']) {
                return (int) $b['_open'] <=> (int) $a['_open'];
            }
            // 4. Shortest distance (nulls last)
            $da = $a['_distance'];
            $db = $b['_distance'];
            if ($da === null && $db !== null) {
                return 1;
            }
            if ($da !== null && $db === null) {
                return -1;
            }
            if ($da !== null && $db !== null && $da != $db) {
                return $da <=> $db;
            }
            // 5. Configured display order
            if ($a['_sort'] !== $b['_sort']) {
                return $a['_sort'] <=> $b['_sort'];
            }
            // 6. Name
            $nameCmp = strcmp($a['_name'], $b['_name']);
            if ($nameCmp !== 0) {
                return $nameCmp;
            }

            // 7. Public ID
            return strcmp($a['_public_id'], $b['_public_id']);
        });

        return array_map(function (array $row) {
            unset(
                $row['_eligible'],
                $row['_accepting'],
                $row['_open'],
                $row['_distance'],
                $row['_sort'],
                $row['_name'],
                $row['_public_id'],
            );

            return $row;
        }, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $sorted
     */
    private function markRecommended(array &$sorted, string $fulfilment): ?string
    {
        $candidate = null;
        foreach ($sorted as $row) {
            if (! empty($row['eligible']) && ! empty($row['accepting_orders'])) {
                $candidate = $row;
                break;
            }
        }

        if (! $candidate) {
            return null;
        }

        $eligibleCount = count(array_filter($sorted, fn ($r) => ! empty($r['eligible']) && ! empty($r['accepting_orders'])));
        $reason = 'NEAREST_ELIGIBLE_BRANCH';
        if ($eligibleCount === 1) {
            $reason = 'ONLY_ELIGIBLE_BRANCH';
        } elseif (! empty($candidate['is_open_now']) && $candidate['distance_km'] !== null) {
            $reason = 'NEAREST_ELIGIBLE_OPEN_BRANCH';
        } elseif ($candidate['distance_km'] === null) {
            $reason = 'ELIGIBLE_DISTANCE_UNAVAILABLE';
        } elseif (! empty($candidate['is_default'])) {
            $reason = 'PRIMARY_ELIGIBLE_BRANCH';
        }

        foreach ($sorted as &$row) {
            if ($row['public_id'] === $candidate['public_id']) {
                $row['recommended'] = true;
                $row['recommendation_reason'] = $reason;
            }
        }
        unset($row);

        return $candidate['public_id'];
    }
}
