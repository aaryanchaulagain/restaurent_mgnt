<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\BusinessUser;
use App\Models\User;
use App\Support\BusinessRoles;

class BranchPolicy
{
    public function view(User $user, Branch $branch): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($this->isBusinessLevel($user, $branch->business_id)) {
            return true;
        }

        return $this->activeBranchRole($user, $branch) !== null;
    }

    public function update(User $user, Branch $branch): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($this->isBusinessAdmin($user, $branch->business_id)) {
            return true;
        }

        $role = $this->activeBranchRole($user, $branch);

        return in_array($role, [BusinessRoles::BRANCH_MANAGER], true);
    }

    public function manageOrders(User $user, Branch $branch): bool
    {
        if ($user->isSuperAdmin() || $this->isBusinessAdmin($user, $branch->business_id)) {
            return true;
        }

        $role = $this->activeBranchRole($user, $branch);

        return in_array($role, [
            BusinessRoles::BRANCH_MANAGER,
            BusinessRoles::ORDER_MANAGER,
            BusinessRoles::KITCHEN_STAFF,
        ], true);
    }

    public function manageStaff(User $user, Branch $branch): bool
    {
        if ($user->isSuperAdmin() || $this->isBusinessAdmin($user, $branch->business_id)) {
            return true;
        }

        return $this->activeBranchRole($user, $branch) === BusinessRoles::BRANCH_MANAGER;
    }

    /**
     * Branch operational reports: business-level roles or branch managers only.
     * Kitchen/order/delivery staff do not receive reporting by default.
     */
    public function viewReports(User $user, Branch $branch): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($this->isBusinessLevel($user, $branch->business_id)) {
            return true;
        }

        return $this->activeBranchRole($user, $branch) === BusinessRoles::BRANCH_MANAGER;
    }

    private function isBusinessAdmin(User $user, int $businessId): bool
    {
        return BusinessUser::query()
            ->where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereIn('role', BusinessRoles::businessManagers())
            ->exists();
    }

    private function isBusinessLevel(User $user, int $businessId): bool
    {
        return BusinessUser::query()
            ->where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereIn('role', BusinessRoles::businessAssignable())
            ->exists();
    }

    private function activeBranchRole(User $user, Branch $branch): ?string
    {
        return BranchUser::query()
            ->where('branch_id', $branch->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('role');
    }
}
