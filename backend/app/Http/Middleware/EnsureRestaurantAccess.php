<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Support\ApiResponse;
use App\Support\BusinessRoles;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves dashboard tenant context from X-Branch-Id (preferred) or legacy
 * X-Restaurant-Id, then authorizes access and sets restaurant_id for operational modules.
 */
class EnsureRestaurantAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return ApiResponse::error('Unauthenticated.', 401);
        }

        $branchHeader = $request->header('X-Branch-Id');
        $restaurantHeader = $request->header('X-Restaurant-Id');
        $businessHeader = $request->header('X-Business-Id');
        $allowAggregate = $request->attributes->get('allow_aggregate_context', false);

        if ($branchHeader) {
            return $this->fromBranch($request, $next, $branchHeader, $businessHeader, $restaurantHeader);
        }

        if ($restaurantHeader) {
            return $this->fromRestaurant($request, $next, $restaurantHeader, $businessHeader);
        }

        if ($user->isSuperAdmin()) {
            if ($allowAggregate) {
                $request->attributes->set('dashboard_aggregate', true);

                return $next($request);
            }

            return ApiResponse::error(
                'Branch or restaurant context required. Send X-Branch-Id (preferred) or X-Restaurant-Id.',
                403,
                code: 'BRANCH_CONTEXT_REQUIRED',
            );
        }

        // Legacy restaurant users / owners without an explicit header.
        $restaurantId = $user->primaryRestaurantId();
        if (! $restaurantId) {
            // Business-only membership with branches: fall back to first accessible branch.
            $branch = $this->firstAccessibleBranch($user);
            if ($branch) {
                return $this->bindHierarchy($request, $next, $branch, null);
            }

            return ApiResponse::error('No active restaurant assignment found.', 403, code: 'BRANCH_ACCESS_DENIED');
        }

        $active = RestaurantUser::query()
            ->where('user_id', $user->id)
            ->where('restaurant_id', $restaurantId)
            ->where('status', 'active')
            ->exists();

        if (! $active && ! $user->canAccessBranch(
            (int) (Restaurant::query()->whereKey($restaurantId)->value('branch_id') ?? 0)
        )) {
            return ApiResponse::error('Restaurant access has been revoked.', 403, code: 'BRANCH_ACCESS_DENIED');
        }

        $restaurant = Restaurant::query()->find($restaurantId);
        if (! $restaurant) {
            return ApiResponse::error('Restaurant not found.', 404, code: 'RESTAURANT_NOT_FOUND');
        }

        if ($restaurant->branch_id) {
            $branch = Branch::query()->find($restaurant->branch_id);
            if ($branch) {
                return $this->bindHierarchy($request, $next, $branch, null);
            }
        }

        $request->attributes->set('restaurant_id', $restaurant->id);
        $request->attributes->set('restaurant_public_id', $restaurant->public_id);
        if ($restaurant->business_id) {
            $request->attributes->set('business_id', $restaurant->business_id);
        }

        return $next($request);
    }

    private function fromBranch(
        Request $request,
        Closure $next,
        string $branchHeader,
        ?string $businessHeader,
        ?string $restaurantHeader,
    ): Response {
        $user = $request->user();
        $branch = Branch::query()->where('public_id', $branchHeader)->first();
        if (! $branch) {
            return ApiResponse::error('Branch not found.', 404, code: 'BRANCH_NOT_FOUND');
        }

        if (! $user->isSuperAdmin() && ! $user->canAccessBranch($branch->id)) {
            return ApiResponse::error('Branch access denied.', 403, code: 'BRANCH_ACCESS_DENIED');
        }

        if ($businessHeader) {
            $business = $branch->business;
            if (! $business || $business->public_id !== $businessHeader) {
                return ApiResponse::error('Branch does not belong to the selected business.', 403, code: 'BRANCH_BUSINESS_MISMATCH');
            }
        }

        if ($restaurantHeader) {
            $restaurant = $branch->restaurant;
            if (! $restaurant || $restaurant->public_id !== $restaurantHeader) {
                return ApiResponse::error(
                    'Restaurant does not belong to the selected branch.',
                    403,
                    code: 'BRANCH_RESTAURANT_MISMATCH',
                );
            }
        }

        return $this->bindHierarchy($request, $next, $branch, $businessHeader);
    }

    private function fromRestaurant(
        Request $request,
        Closure $next,
        string $restaurantHeader,
        ?string $businessHeader,
    ): Response {
        $user = $request->user();
        $restaurant = Restaurant::query()->where('public_id', $restaurantHeader)->first();
        if (! $restaurant) {
            return ApiResponse::error('Restaurant not found.', 404, code: 'RESTAURANT_NOT_FOUND');
        }

        if ($user->isSuperAdmin()) {
            if ($restaurant->branch_id) {
                $branch = Branch::query()->find($restaurant->branch_id);
                if ($branch) {
                    return $this->bindHierarchy($request, $next, $branch, $businessHeader);
                }
            }

            $request->attributes->set('restaurant_id', $restaurant->id);
            $request->attributes->set('restaurant_public_id', $restaurant->public_id);
            if ($restaurant->business_id) {
                $request->attributes->set('business_id', $restaurant->business_id);
            }

            return $next($request);
        }

        $hasLegacy = RestaurantUser::query()
            ->where('user_id', $user->id)
            ->where('restaurant_id', $restaurant->id)
            ->where('status', 'active')
            ->exists();

        $hasBranch = $restaurant->branch_id && $user->canAccessBranch((int) $restaurant->branch_id);

        if (! $hasLegacy && ! $hasBranch) {
            return ApiResponse::error('Branch access denied.', 403, code: 'BRANCH_ACCESS_DENIED');
        }

        if ($restaurant->branch_id) {
            $branch = Branch::query()->find($restaurant->branch_id);
            if ($branch) {
                return $this->bindHierarchy($request, $next, $branch, $businessHeader);
            }
        }

        $request->attributes->set('restaurant_id', $restaurant->id);
        $request->attributes->set('restaurant_public_id', $restaurant->public_id);

        return $next($request);
    }

    private function bindHierarchy(Request $request, Closure $next, Branch $branch, ?string $businessHeader): Response
    {
        $business = $branch->business;
        $restaurant = $branch->restaurant;

        if (! $business) {
            Log::error('Branch missing business', ['branch_id' => $branch->id]);

            return ApiResponse::error('Branch hierarchy is misconfigured.', 500, code: 'BRANCH_BUSINESS_MISMATCH');
        }

        if ($businessHeader && $business->public_id !== $businessHeader) {
            return ApiResponse::error('Branch does not belong to the selected business.', 403, code: 'BRANCH_BUSINESS_MISMATCH');
        }

        if (! $restaurant) {
            Log::warning('Branch missing linked restaurant', ['branch_id' => $branch->id]);

            return ApiResponse::error(
                'Branch is not operational (missing linked restaurant).',
                409,
                code: 'BRANCH_NOT_OPERATIONAL',
            );
        }

        if ((int) $restaurant->branch_id !== (int) $branch->id
            || (int) $restaurant->business_id !== (int) $business->id
            || (int) $branch->restaurant_id !== (int) $restaurant->id) {
            Log::error('Branch/restaurant hierarchy mismatch', [
                'branch_id' => $branch->id,
                'restaurant_id' => $restaurant->id,
            ]);

            return ApiResponse::error('Branch hierarchy is misconfigured.', 500, code: 'BRANCH_RESTAURANT_MISMATCH');
        }

        $request->attributes->set('business_id', $business->id);
        $request->attributes->set('business_public_id', $business->public_id);
        $request->attributes->set('branch_id', $branch->id);
        $request->attributes->set('branch_public_id', $branch->public_id);
        $request->attributes->set('restaurant_id', $restaurant->id);
        $request->attributes->set('restaurant_public_id', $restaurant->public_id);
        $request->attributes->set('dashboard_aggregate', false);

        return $next($request);
    }

    private function firstAccessibleBranch($user): ?Branch
    {
        $businessIds = $user->businessUsers()
            ->where('status', 'active')
            ->whereIn('role', BusinessRoles::businessManagers())
            ->pluck('business_id');

        if ($businessIds->isNotEmpty()) {
            $branch = Branch::query()
                ->whereIn('business_id', $businessIds)
                ->where('is_default', true)
                ->orderBy('id')
                ->first();
            if ($branch) {
                return $branch;
            }

            return Branch::query()->whereIn('business_id', $businessIds)->orderBy('id')->first();
        }

        $branchId = $user->branchUsers()->where('status', 'active')->orderBy('id')->value('branch_id');

        return $branchId ? Branch::query()->find($branchId) : null;
    }
}
