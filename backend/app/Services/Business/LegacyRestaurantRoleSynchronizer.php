<?php

namespace App\Services\Business;

use App\Models\Branch;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Restaurant\RestaurantMembershipService;
use App\Support\BusinessRoles;
use Illuminate\Http\Request;

/**
 * Temporary compatibility: keep restaurant_users in sync with branch/business roles
 * until operational authorization is fully branch-native.
 */
class LegacyRestaurantRoleSynchronizer
{
    public function __construct(
        private readonly RestaurantMembershipService $membership,
    ) {}

    public function syncBranchAssignment(
        Branch $branch,
        User $user,
        string $branchRole,
        User $actor,
        ?Request $request = null,
        string $status = 'active',
    ): void {
        $restaurant = $branch->restaurant;
        if (! $restaurant) {
            return;
        }

        $legacyRole = BusinessRoles::toLegacyRestaurantRole($branchRole);
        if (! $legacyRole) {
            return;
        }

        // Prefer stronger business-level legacy role if already a business owner/admin.
        $legacyRole = $this->preferStrongerLegacyRole($branch->business_id, $user, $legacyRole);

        if ($status !== 'active') {
            $this->membership->revoke($restaurant, $user, $actor, $request);

            return;
        }

        $this->membership->assign($restaurant, $user, $legacyRole, $actor, $request, $status);
    }

    public function removeBranchAssignment(
        Branch $branch,
        User $user,
        User $actor,
        ?Request $request = null,
    ): void {
        $restaurant = $branch->restaurant;
        if (! $restaurant) {
            return;
        }

        // Keep restaurant access if still a business manager for this business.
        if ($user->businessUsers()
            ->where('business_id', $branch->business_id)
            ->where('status', 'active')
            ->whereIn('role', BusinessRoles::businessManagers())
            ->exists()) {
            $legacy = BusinessRoles::toLegacyRestaurantRole(
                $user->businessUsers()
                    ->where('business_id', $branch->business_id)
                    ->where('status', 'active')
                    ->value('role') ?? BusinessRoles::BUSINESS_ADMIN
            );
            if ($legacy) {
                $this->membership->assign($restaurant, $user, $legacy, $actor, $request);

                return;
            }
        }

        $this->membership->revoke($restaurant, $user, $actor, $request);
    }

    /**
     * Ensure business managers have legacy restaurant access on every branch restaurant.
     */
    public function syncBusinessManagerAcrossBranches(
        int $businessId,
        User $user,
        string $businessRole,
        User $actor,
        ?Request $request = null,
        string $status = 'active',
    ): void {
        $legacy = BusinessRoles::toLegacyRestaurantRole($businessRole);
        if (! $legacy) {
            return;
        }

        $branches = Branch::query()
            ->where('business_id', $businessId)
            ->whereNotNull('restaurant_id')
            ->with('restaurant')
            ->get();

        foreach ($branches as $branch) {
            /** @var Restaurant|null $restaurant */
            $restaurant = $branch->restaurant;
            if (! $restaurant) {
                continue;
            }

            if ($status !== 'active') {
                $this->membership->revoke($restaurant, $user, $actor, $request);
                continue;
            }

            $this->membership->assign($restaurant, $user, $legacy, $actor, $request, $status);
        }
    }

    private function preferStrongerLegacyRole(int $businessId, User $user, string $candidate): string
    {
        $businessRole = $user->businessUsers()
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->value('role');

        $fromBusiness = $businessRole
            ? BusinessRoles::toLegacyRestaurantRole($businessRole)
            : null;

        $rank = [
            'restaurant_staff' => 1,
            'restaurant_manager' => 2,
            'restaurant_owner' => 3,
        ];

        if ($fromBusiness && ($rank[$fromBusiness] ?? 0) > ($rank[$candidate] ?? 0)) {
            return $fromBusiness;
        }

        return $candidate;
    }
}
