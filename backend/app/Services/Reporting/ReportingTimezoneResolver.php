<?php

namespace App\Services\Reporting;

use App\Models\Branch;
use App\Models\Business;
use App\Support\BranchStatuses;

final class ReportingTimezoneResolver
{
    public function forBusiness(Business $business): string
    {
        $primary = Branch::query()
            ->where('business_id', $business->id)
            ->where('is_default', true)
            ->whereIn('status', [BranchStatuses::ACTIVE, BranchStatuses::PAUSED])
            ->first();

        if ($primary?->timezone) {
            return (string) $primary->timezone;
        }

        $any = Branch::query()
            ->where('business_id', $business->id)
            ->whereNotNull('timezone')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->first();

        if ($any?->timezone) {
            return (string) $any->timezone;
        }

        $restaurantTz = Branch::query()
            ->where('business_id', $business->id)
            ->whereNotNull('restaurant_id')
            ->with('restaurant')
            ->get()
            ->pluck('restaurant.timezone')
            ->filter()
            ->first();

        return $restaurantTz ?: (string) config('restaurant.default_timezone', config('app.timezone', 'UTC'));
    }

    public function forBranch(Branch $branch): string
    {
        if ($branch->timezone) {
            return (string) $branch->timezone;
        }

        $branch->loadMissing('restaurant');

        return $branch->restaurant?->timezone
            ?: (string) config('restaurant.default_timezone', config('app.timezone', 'UTC'));
    }
}
