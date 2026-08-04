<?php

namespace App\Http\Middleware;

use App\Exceptions\ModulePermissionException;
use App\Models\Branch;
use App\Services\Branch\BranchPermissionService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant-aware permission check.
 * When a branch is bound (X-Branch-Id / EnsureRestaurantAccess), uses
 * BranchPermissionService role templates instead of unscoped user_roles permissions.
 */
class EnsurePermission
{
    public function __construct(
        private readonly BranchPermissionService $permissions,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::error('Unauthenticated.', 401);
        }

        // Super admins operating a partner restaurant via tenant context bypass module templates.
        if ($user->isSuperAdmin() && $request->attributes->has('restaurant_id')) {
            return $next($request);
        }

        $branch = $this->resolveBranch($request);
        if ($branch) {
            try {
                $this->permissions->authorize($user, $branch, $permission);
            } catch (ModulePermissionException $e) {
                return ApiResponse::error($e->getMessage(), $e->httpStatus, code: $e->errorCode);
            }

            return $next($request);
        }

        // Platform / non-tenant routes: use seeded role permissions (incl. super admin grants).
        if (! $user->hasPermission($permission)) {
            return ApiResponse::error(
                'You do not have permission to access this resource.',
                403,
                code: 'MODULE_PERMISSION_DENIED',
            );
        }

        return $next($request);
    }

    private function resolveBranch(Request $request): ?Branch
    {
        $branchId = $request->attributes->get('branch_id');
        if ($branchId) {
            return Branch::query()->find($branchId);
        }

        $restaurantId = $request->attributes->get('restaurant_id');
        if ($restaurantId) {
            return Branch::query()->where('restaurant_id', $restaurantId)->first();
        }

        return null;
    }
}
