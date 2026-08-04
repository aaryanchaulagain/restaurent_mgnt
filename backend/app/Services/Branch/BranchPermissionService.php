<?php

namespace App\Services\Branch;

use App\Exceptions\ModulePermissionException;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\User;
use App\Support\BranchRolePermissionMatrix;
use App\Support\BusinessRoles;

class BranchPermissionService
{
    /**
     * Resolve the effective hierarchy role for this user at a branch.
     */
    public function roleFor(User $user, Branch $branch): ?string
    {
        if ($user->isSuperAdmin()) {
            return 'super_admin';
        }

        $businessRole = BusinessUser::query()
            ->where('business_id', $branch->business_id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('role');

        if (in_array($businessRole, BusinessRoles::businessManagers(), true)) {
            return $businessRole;
        }

        if ($businessRole === BusinessRoles::ACCOUNTANT) {
            return BusinessRoles::ACCOUNTANT;
        }

        $branchRole = BranchUser::query()
            ->where('branch_id', $branch->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('role');

        if (is_string($branchRole) && $branchRole !== '') {
            return $branchRole;
        }

        // Legacy fallback: restaurant membership without branch_users row.
        $legacy = $user->roles()
            ->wherePivot('restaurant_id', $branch->restaurant_id)
            ->pluck('slug')
            ->all();

        if (in_array('restaurant_owner', $legacy, true)) {
            return BusinessRoles::BUSINESS_OWNER;
        }
        if (in_array('restaurant_manager', $legacy, true)) {
            return BusinessRoles::BRANCH_MANAGER;
        }
        if (in_array('restaurant_staff', $legacy, true)) {
            return BusinessRoles::ORDER_MANAGER;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function permissionsFor(User $user, Branch $branch): array
    {
        if ($user->isSuperAdmin()) {
            return BranchRolePermissionMatrix::businessOwner();
        }

        $role = $this->roleFor($user, $branch);
        if (! $role) {
            return [];
        }

        return BranchRolePermissionMatrix::forRole($role);
    }

    public function allows(User $user, Branch $branch, string $permission): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! $user->canAccessBranch($branch->id)) {
            return false;
        }

        return in_array($permission, $this->permissionsFor($user, $branch), true);
    }

    public function authorize(User $user, Branch $branch, string $permission): void
    {
        if ($this->allows($user, $branch, $permission)) {
            return;
        }

        $code = match (true) {
            str_contains($permission, 'payment') || str_contains($permission, 'finance') || str_contains($permission, 'settlement') || str_contains($permission, 'refund')
                => 'FINANCE_PERMISSION_DENIED',
            str_contains($permission, 'inventory') => 'INVENTORY_PERMISSION_DENIED',
            str_contains($permission, 'order') => 'ORDER_PERMISSION_DENIED',
            str_contains($permission, 'staff') || str_contains($permission, 'invite') => 'STAFF_ROLE_PERMISSION_DENIED',
            str_contains($permission, 'service_area') || str_contains($permission, 'delivery') => 'DELIVERY_PERMISSION_DENIED',
            default => 'MODULE_PERMISSION_DENIED',
        };

        throw new ModulePermissionException(
            $code,
            'You do not have permission to perform this action for this branch.',
            403,
        );
    }

    public function hasBusinessWideAccess(User $user, Business $business): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return BusinessUser::query()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereIn('role', BusinessRoles::businessManagers())
            ->exists();
    }

    /**
     * @return list<int>
     */
    public function authorizedBranchIds(User $user): array
    {
        if ($user->isSuperAdmin()) {
            return Branch::query()->pluck('id')->all();
        }

        $businessIds = BusinessUser::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereIn('role', array_merge(BusinessRoles::businessManagers(), [BusinessRoles::ACCOUNTANT]))
            ->pluck('business_id');

        $fromBusiness = Branch::query()->whereIn('business_id', $businessIds)->pluck('id');

        $fromBranch = BranchUser::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('branch_id');

        return $fromBusiness->merge($fromBranch)->unique()->values()->all();
    }
}
