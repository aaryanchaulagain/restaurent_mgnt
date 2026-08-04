<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\CustomerAddress;
use App\Models\RestaurantServiceArea;
use App\Support\BranchStatuses;
use App\Support\GeoDistance;
use Illuminate\Console\Command;

/**
 * Read-only integrity report for branch location and delivery configuration.
 */
class BranchLocationIntegrityCommand extends Command
{
    protected $signature = 'branch:location-integrity {--repair-coords : Clamp only unambiguous out-of-range coordinate formatting is not auto-fixed; reserved}';

    protected $description = 'Report branch location / service-area integrity issues (read-only by default)';

    public function handle(): int
    {
        $issues = [];

        Branch::query()->with(['restaurant.addresses'])->chunkById(100, function ($branches) use (&$issues) {
            foreach ($branches as $branch) {
                $restaurant = $branch->restaurant;

                if ($branch->latitude !== null && ! GeoDistance::isValidLatitude($branch->latitude)) {
                    $issues[] = "branch:{$branch->public_id} invalid latitude";
                }
                if ($branch->longitude !== null && ! GeoDistance::isValidLongitude($branch->longitude)) {
                    $issues[] = "branch:{$branch->public_id} invalid longitude";
                }

                if ($restaurant) {
                    if ((int) $branch->restaurant_id !== (int) $restaurant->id
                        || (int) $restaurant->branch_id !== (int) $branch->id) {
                        $issues[] = "branch:{$branch->public_id} branch/restaurant mutual-link mismatch";
                    }

                    $deliveryEnabled = (bool) $restaurant->restaurant_delivery_enabled;
                    if ($deliveryEnabled && in_array($branch->status, [BranchStatuses::ACTIVE, BranchStatuses::PAUSED], true)) {
                        $areas = RestaurantServiceArea::query()
                            ->where('restaurant_id', $restaurant->id)
                            ->where('is_active', true)
                            ->get();
                        if ($areas->isEmpty()) {
                            $issues[] = "branch:{$branch->public_id} delivery enabled but no active service area";
                        }
                        foreach ($areas as $area) {
                            if ($area->type === 'radius') {
                                $primary = $restaurant->addresses->firstWhere('is_primary', true)
                                    ?? $restaurant->addresses->first();
                                $hasCenter = ($branch->latitude && $branch->longitude)
                                    || ($primary?->latitude && $primary?->longitude);
                                if (! $hasCenter) {
                                    $issues[] = "branch:{$branch->public_id} radius service area without coordinates";
                                }
                            }
                        }
                    }

                    if ($deliveryEnabled && ! $restaurant->restaurant_delivery_enabled) {
                        $issues[] = "branch:{$branch->public_id} delivery flag mismatch vs restaurant";
                    }
                }

                if (in_array($branch->status, [BranchStatuses::ACTIVE], true)
                    && ! $branch->address_line && ! $branch->city && ! $branch->postcode) {
                    $issues[] = "branch:{$branch->public_id} active branch missing public address fields";
                }
            }
        });

        CustomerAddress::query()->chunkById(200, function ($addresses) use (&$issues) {
            foreach ($addresses as $address) {
                if ($address->latitude !== null && ! GeoDistance::isValidLatitude($address->latitude)) {
                    $issues[] = "customer_address:{$address->public_id} invalid latitude";
                }
                if ($address->longitude !== null && ! GeoDistance::isValidLongitude($address->longitude)) {
                    $issues[] = "customer_address:{$address->public_id} invalid longitude";
                }
            }
        });

        if ($issues === []) {
            $this->info('No branch location integrity issues found.');

            return self::SUCCESS;
        }

        $this->warn(count($issues).' integrity issue(s):');
        foreach ($issues as $issue) {
            $this->line(' - '.$issue);
        }

        return self::SUCCESS;
    }
}
