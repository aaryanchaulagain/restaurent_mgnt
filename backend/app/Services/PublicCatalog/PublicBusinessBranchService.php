<?php

namespace App\Services\PublicCatalog;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Restaurant;
use App\Support\BranchStatuses;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves publicly browsable businesses/branches and validates branch↔restaurant integrity.
 * Public routes must never trust tenant headers or client-supplied restaurant IDs.
 */
class PublicBusinessBranchService
{
    /**
     * Resolve a publicly discoverable business by slug.
     */
    public function resolveBusiness(string $businessSlug): Business
    {
        $business = Business::query()
            ->where('slug', $businessSlug)
            ->where('status', 'active')
            ->whereNull('suspended_at')
            ->first();

        if (! $business) {
            throw new NotFoundHttpException('Business not found.');
        }

        return $business;
    }

    /**
     * Publicly visible branches for a business, deterministic order.
     *
     * @return Collection<int, Branch>
     */
    public function listPublicBranches(Business $business): Collection
    {
        return $this->publicBranchesQuery($business)
            ->with(['restaurant.business'])
            ->get()
            ->filter(fn (Branch $branch) => $this->isBranchPubliclyBrowsable($branch))
            ->values();
    }

    /**
     * Resolve a branch only inside the given business; 404 on mismatch or hidden.
     */
    public function resolvePublicBranch(Business $business, string $branchPublicId): Branch
    {
        $branch = Branch::query()
            ->where('business_id', $business->id)
            ->where('public_id', $branchPublicId)
            ->with(['restaurant.business'])
            ->first();

        if (! $branch || ! $this->isBranchPubliclyBrowsable($branch)) {
            throw new NotFoundHttpException('Branch not found.');
        }

        return $branch;
    }

    /**
     * Canonical operational restaurant for a public branch.
     * Validates mutual FKs; never falls back to another restaurant.
     */
    public function resolveLinkedRestaurant(Branch $branch): Restaurant
    {
        $restaurant = $branch->restaurant;
        if (! $restaurant) {
            throw new NotFoundHttpException('Branch not found.');
        }

        if (! $this->relationshipsAreConsistent($branch, $restaurant)) {
            throw new NotFoundHttpException('Branch not found.');
        }

        if (! $this->isRestaurantPubliclyBrowsable($restaurant)) {
            throw new NotFoundHttpException('Branch not found.');
        }

        return $restaurant;
    }

    public function isBranchPubliclyBrowsable(Branch $branch): bool
    {
        if ($branch->suspended_at !== null) {
            return false;
        }

        if (! in_array($branch->status, [BranchStatuses::ACTIVE, BranchStatuses::PAUSED], true)) {
            return false;
        }

        $restaurant = $branch->restaurant;
        if (! $restaurant) {
            return false;
        }

        if (! $this->relationshipsAreConsistent($branch, $restaurant)) {
            return false;
        }

        return $this->isRestaurantPubliclyBrowsable($restaurant);
    }

    public function isRestaurantPubliclyBrowsable(Restaurant $restaurant): bool
    {
        if ($restaurant->suspended_at !== null) {
            return false;
        }

        if ($restaurant->published_at === null) {
            return false;
        }

        $status = $restaurant->status instanceof RestaurantStatus
            ? $restaurant->status
            : RestaurantStatus::tryFrom((string) $restaurant->status);

        return $status !== null && $status->isPubliclyVisible();
    }

    /**
     * Apply public restaurant visibility to a query (Active + TemporarilyClosed, published, not suspended).
     */
    public function scopePublicRestaurants(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [RestaurantStatus::Active->value, RestaurantStatus::TemporarilyClosed->value])
            ->whereNotNull('published_at')
            ->whereNull('suspended_at');
    }

    public function relationshipsAreConsistent(Branch $branch, Restaurant $restaurant): bool
    {
        if ((int) $branch->restaurant_id !== (int) $restaurant->id) {
            return false;
        }

        if ((int) $restaurant->branch_id !== (int) $branch->id) {
            return false;
        }

        if ((int) $restaurant->business_id !== (int) $branch->business_id) {
            return false;
        }

        return true;
    }

    /**
     * Prefer default branch among public ones; otherwise first by deterministic order.
     */
    public function preferredPublicBranch(Business $business): ?Branch
    {
        $branches = $this->listPublicBranches($business);
        if ($branches->isEmpty()) {
            return null;
        }

        return $branches->first(fn (Branch $b) => $b->is_default) ?? $branches->first();
    }

    private function publicBranchesQuery(Business $business): Builder
    {
        return Branch::query()
            ->where('business_id', $business->id)
            ->whereNull('suspended_at')
            ->whereIn('status', [BranchStatuses::ACTIVE, BranchStatuses::PAUSED])
            ->whereNotNull('restaurant_id')
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('public_id');
    }
}
