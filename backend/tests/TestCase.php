<?php

namespace Tests;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Restaurant;
use App\Services\Business\BusinessHierarchyMigrator;
use App\Support\BranchStatuses;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000',
            'Accept' => 'application/json',
        ]);

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /**
     * Ensure Business → Branch ↔ Restaurant links exist for cart/order tests.
     */
    protected function ensureRestaurantHierarchy(Restaurant $restaurant): Restaurant
    {
        $restaurant->refresh();

        if ($restaurant->branch_id && $restaurant->business_id) {
            return $restaurant->fresh(['branch', 'business']) ?? $restaurant;
        }

        // Preserve an existing business (e.g. grocery fixtures) and only add the branch link.
        if ($restaurant->business_id && ! $restaurant->branch_id) {
            $business = Business::query()->findOrFail($restaurant->business_id);
            $branch = Branch::query()->create([
                'public_id' => (string) Str::uuid(),
                'business_id' => $business->id,
                'restaurant_id' => $restaurant->id,
                'name' => ($restaurant->trading_name ?: 'Main').' — Main',
                'code' => 'MAIN',
                'accepting_orders' => (bool) $restaurant->accepting_orders,
                'is_default' => true,
                'status' => BranchStatuses::ACTIVE,
                'timezone' => $restaurant->timezone,
                'country' => 'AU',
                'sort_order' => 0,
            ]);
            $restaurant->forceFill(['branch_id' => $branch->id])->save();

            return $restaurant->fresh(['branch', 'business']);
        }

        app(BusinessHierarchyMigrator::class)->migrateRestaurant($restaurant->fresh());

        return Restaurant::query()->with(['branch', 'business'])->findOrFail($restaurant->id);
    }
}
