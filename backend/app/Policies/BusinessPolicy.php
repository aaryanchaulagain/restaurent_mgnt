<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\User;
use App\Support\BusinessRoles;

class BusinessPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $this->hasAnyBusinessMembership($user);
    }

    public function view(User $user, Business $business): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->activeBusinessRole($user, $business) !== null;
    }

    public function update(User $user, Business $business): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return in_array($this->activeBusinessRole($user, $business), BusinessRoles::businessManagers(), true);
    }

    public function manageBranches(User $user, Business $business): bool
    {
        return $this->update($user, $business);
    }

    public function manageStaff(User $user, Business $business): bool
    {
        return $this->update($user, $business);
    }

    /**
     * Business aggregate reports: owners, admins, accountants, super admin.
     * Branch-only staff must not access all-business aggregates.
     */
    public function viewReports(User $user, Business $business): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $role = $this->activeBusinessRole($user, $business);

        return in_array($role, [
            BusinessRoles::BUSINESS_OWNER,
            BusinessRoles::BUSINESS_ADMIN,
            BusinessRoles::ACCOUNTANT,
        ], true);
    }

    public function viewFinance(User $user, Business $business): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $role = $this->activeBusinessRole($user, $business);

        return in_array($role, [
            BusinessRoles::BUSINESS_OWNER,
            BusinessRoles::BUSINESS_ADMIN,
            BusinessRoles::ACCOUNTANT,
        ], true);
    }

    public function suspend(User $user, Business $business): bool
    {
        return $user->isSuperAdmin();
    }

    private function activeBusinessRole(User $user, Business $business): ?string
    {
        return BusinessUser::query()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('role');
    }

    private function hasAnyBusinessMembership(User $user): bool
    {
        return BusinessUser::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }
}
