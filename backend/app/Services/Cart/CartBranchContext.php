<?php

namespace App\Services\Cart;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Branch;
use App\Models\Restaurant;
use App\Support\BranchStatuses;
use App\Support\BusinessTypes;

/**
 * Derive safe public business/branch summaries from a cart's restaurant lock.
 * restaurant_id remains the operational cart lock (1:1 with branch).
 */
class CartBranchContext
{
    /**
     * @return array{
     *   business: array{public_id: string, slug: string, name: string, business_type: string}|null,
     *   branch: array{public_id: string, name: string, city: ?string, state: ?string}|null,
     *   restaurant: array{slug: string, trading_name: string, minimum_order_cents: mixed},
     *   accepting_orders: bool,
     *   is_temporarily_closed: bool
     * }
     */
    public function summarize(Restaurant $restaurant): array
    {
        $restaurant->loadMissing(['business', 'branch']);

        $branch = $restaurant->branch;
        $business = $restaurant->business;

        $status = $restaurant->status instanceof RestaurantStatus
            ? $restaurant->status
            : RestaurantStatus::tryFrom((string) $restaurant->status);

        $temporarilyClosed = $status === RestaurantStatus::TemporarilyClosed
            || ($branch && $branch->status === BranchStatuses::PAUSED);

        $accepting = (bool) $restaurant->accepting_orders
            && $status === RestaurantStatus::Active
            && (! $branch || ($branch->status === BranchStatuses::ACTIVE && $branch->accepting_orders));

        return [
            'business' => $business ? [
                'public_id' => $business->public_id,
                'slug' => $business->slug,
                'name' => $business->name,
                'business_type' => BusinessTypes::normalize($business->business_type),
            ] : null,
            'branch' => $branch ? [
                'public_id' => $branch->public_id,
                'name' => $branch->name,
                'city' => $branch->city,
                'state' => $branch->state,
            ] : null,
            'restaurant' => [
                'slug' => $restaurant->slug,
                'trading_name' => $restaurant->trading_name,
                'minimum_order_cents' => $restaurant->minimum_order_cents,
            ],
            'accepting_orders' => $accepting,
            'is_temporarily_closed' => $temporarilyClosed,
        ];
    }

    /**
     * Validate restaurant↔branch↔business integrity for ordering.
     *
     * @return array{ok: bool, code: ?string, message: ?string}
     */
    public function validateForOrdering(Restaurant $restaurant): array
    {
        $restaurant->loadMissing(['business', 'branch']);

        if ($restaurant->suspended_at !== null) {
            return ['ok' => false, 'code' => 'CART_BRANCH_UNAVAILABLE', 'message' => 'This location is unavailable.'];
        }

        $status = $restaurant->status instanceof RestaurantStatus
            ? $restaurant->status
            : RestaurantStatus::tryFrom((string) $restaurant->status);

        if ($status === null || ! in_array($status, [RestaurantStatus::Active, RestaurantStatus::TemporarilyClosed], true)) {
            return ['ok' => false, 'code' => 'CART_BRANCH_UNAVAILABLE', 'message' => 'This location is unavailable.'];
        }

        if ($restaurant->published_at === null) {
            return ['ok' => false, 'code' => 'CART_BRANCH_UNAVAILABLE', 'message' => 'This location is unavailable.'];
        }

        $branch = $restaurant->branch;
        if ($branch) {
            if (! $this->linksAreConsistent($branch, $restaurant)) {
                return [
                    'ok' => false,
                    'code' => 'CART_BRANCH_RESTAURANT_MISMATCH',
                    'message' => 'This location cannot accept orders right now.',
                ];
            }

            if ($branch->suspended_at !== null
                || ! in_array($branch->status, [BranchStatuses::ACTIVE, BranchStatuses::PAUSED], true)) {
                return ['ok' => false, 'code' => 'CART_BRANCH_UNAVAILABLE', 'message' => 'This location is unavailable.'];
            }
        }

        if ($status === RestaurantStatus::TemporarilyClosed
            || ($branch && $branch->status === BranchStatuses::PAUSED)
            || ! $restaurant->accepting_orders
            || ($branch && ! $branch->accepting_orders)) {
            return [
                'ok' => false,
                'code' => 'CART_BRANCH_NOT_ACCEPTING_ORDERS',
                'message' => 'This location is not accepting orders right now.',
            ];
        }

        if ($status !== RestaurantStatus::Active) {
            return ['ok' => false, 'code' => 'CART_BRANCH_UNAVAILABLE', 'message' => 'This location is unavailable.'];
        }

        return ['ok' => true, 'code' => null, 'message' => null];
    }

    public function linksAreConsistent(Branch $branch, Restaurant $restaurant): bool
    {
        if ((int) $branch->restaurant_id !== (int) $restaurant->id) {
            return false;
        }
        if ((int) $restaurant->branch_id !== (int) $branch->id) {
            return false;
        }
        if ($restaurant->business_id !== null
            && (int) $restaurant->business_id !== (int) $branch->business_id) {
            return false;
        }

        return true;
    }
}
