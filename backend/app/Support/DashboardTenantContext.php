<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Restaurant;
use Illuminate\Http\Request;

/**
 * Unified dashboard tenant context resolved by middleware.
 *
 * Users choose a Branch; the system authorizes it and resolves the linked Restaurant
 * so existing operational modules keep working.
 */
final class DashboardTenantContext
{
    public static function businessId(Request $request): ?int
    {
        $id = $request->attributes->get('business_id');

        return $id !== null ? (int) $id : null;
    }

    public static function branchId(Request $request): ?int
    {
        $id = $request->attributes->get('branch_id');

        return $id !== null ? (int) $id : null;
    }

    public static function restaurantId(Request $request): ?int
    {
        $id = $request->attributes->get('restaurant_id');

        return $id !== null ? (int) $id : null;
    }

    public static function isAggregate(Request $request): bool
    {
        return (bool) $request->attributes->get('dashboard_aggregate', false);
    }

    public static function requireBranch(Request $request): Branch
    {
        $id = self::branchId($request);
        if (! $id) {
            abort(response()->json([
                'success' => false,
                'code' => 'BRANCH_CONTEXT_REQUIRED',
                'message' => 'Branch context required. Send X-Branch-Id header.',
                'data' => null,
                'meta' => null,
                'errors' => null,
            ], 403));
        }

        return Branch::query()->findOrFail($id);
    }

    public static function requireRestaurant(Request $request): Restaurant
    {
        return RestaurantContext::restaurant($request);
    }

    public static function business(Request $request): ?Business
    {
        $id = self::businessId($request);

        return $id ? Business::query()->find($id) : null;
    }

    public static function branch(Request $request): ?Branch
    {
        $id = self::branchId($request);

        return $id ? Branch::query()->find($id) : null;
    }

    /**
     * @return array{
     *   aggregate: bool,
     *   business_id: int|null,
     *   business_public_id: string|null,
     *   branch_id: int|null,
     *   branch_public_id: string|null,
     *   restaurant_id: int|null,
     *   restaurant_public_id: string|null
     * }
     */
    public static function snapshot(Request $request): array
    {
        return [
            'aggregate' => self::isAggregate($request),
            'business_id' => self::businessId($request),
            'business_public_id' => $request->attributes->get('business_public_id'),
            'branch_id' => self::branchId($request),
            'branch_public_id' => $request->attributes->get('branch_public_id'),
            'restaurant_id' => self::restaurantId($request),
            'restaurant_public_id' => $request->attributes->get('restaurant_public_id'),
        ];
    }
}
