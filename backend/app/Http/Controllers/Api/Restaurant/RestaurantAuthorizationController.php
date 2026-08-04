<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Branch\BranchPermissionService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class RestaurantAuthorizationController extends Controller
{
    public function __construct(
        private readonly BranchPermissionService $permissions,
    ) {}

    /**
     * Effective role + permissions for the current tenant branch context.
     */
    public function show(Request $request)
    {
        $branchId = $request->attributes->get('branch_id');
        if (! $branchId) {
            return ApiResponse::error(
                'Branch context required.',
                403,
                code: 'BRANCH_CONTEXT_REQUIRED',
            );
        }

        $branch = Branch::query()->with('business')->find($branchId);
        if (! $branch) {
            return ApiResponse::error('Branch not found.', 404, code: 'BRANCH_NOT_FOUND');
        }

        $user = $request->user();

        return ApiResponse::success([
            'business' => [
                'public_id' => $branch->business?->public_id,
                'business_type' => $branch->business?->business_type ?? 'restaurant',
            ],
            'branch' => [
                'public_id' => $branch->public_id,
                'name' => $branch->name,
            ],
            'role' => $this->permissions->roleFor($user, $branch),
            'permissions' => $this->permissions->permissionsFor($user, $branch),
        ]);
    }
}
