<?php

namespace App\Services\Restaurant;

use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RestaurantMembershipService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  'restaurant_owner'|'restaurant_manager'|'restaurant_staff'  $roleSlug
     */
    public function assign(
        Restaurant $restaurant,
        User $user,
        string $roleSlug,
        User $actor,
        ?Request $request = null,
        string $status = 'active',
    ): RestaurantUser {
        $allowed = ['restaurant_owner', 'restaurant_manager', 'restaurant_staff'];
        if (! in_array($roleSlug, $allowed, true)) {
            throw ValidationException::withMessages(['role' => ['Invalid restaurant role.']]);
        }

        if ($user->isSuperAdmin()) {
            throw ValidationException::withMessages(['user' => ['Cannot assign restaurant roles to a super admin.']]);
        }

        return DB::transaction(function () use ($restaurant, $user, $roleSlug, $actor, $request, $status) {
            $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

            $existingPivot = $user->roles()
                ->where('roles.id', $role->id)
                ->wherePivot('restaurant_id', $restaurant->id)
                ->exists();

            if (! $existingPivot) {
                $user->roles()->attach($role->id, ['restaurant_id' => $restaurant->id]);
            }

            // Drop other restaurant-scoped role pivots for this restaurant so role is singular.
            $otherRoleIds = Role::query()
                ->whereIn('slug', ['restaurant_owner', 'restaurant_manager', 'restaurant_staff'])
                ->where('id', '!=', $role->id)
                ->pluck('id');
            if ($otherRoleIds->isNotEmpty()) {
                $user->roles()->wherePivot('restaurant_id', $restaurant->id)->detach($otherRoleIds);
            }

            $membership = RestaurantUser::query()->updateOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'user_id' => $user->id,
                ],
                [
                    'role_id' => $role->id,
                    'status' => $status,
                    'invited_by' => $actor->id,
                    'joined_at' => $status === 'active' ? now() : null,
                ],
            );

            $this->auditLogger->log(
                'restaurant.member_assigned',
                $actor,
                $user,
                restaurantId: $restaurant->id,
                metadata: ['role' => $roleSlug, 'status' => $status],
                request: $request,
            );

            return $membership->load(['user', 'role']);
        });
    }

    public function revoke(Restaurant $restaurant, User $user, User $actor, ?Request $request = null): void
    {
        if ($user->isSuperAdmin()) {
            throw ValidationException::withMessages(['user' => ['Cannot revoke a super admin via restaurant membership.']]);
        }

        DB::transaction(function () use ($restaurant, $user, $actor, $request) {
            $membership = RestaurantUser::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('user_id', $user->id)
                ->first();

            if (! $membership) {
                return;
            }

            $membership->forceFill(['status' => 'removed'])->save();

            $restaurantRoleIds = Role::query()
                ->whereIn('slug', ['restaurant_owner', 'restaurant_manager', 'restaurant_staff'])
                ->pluck('id');
            $user->roles()->wherePivot('restaurant_id', $restaurant->id)->detach($restaurantRoleIds);

            $this->auditLogger->log(
                'restaurant.member_revoked',
                $actor,
                $user,
                restaurantId: $restaurant->id,
                request: $request,
            );
        });
    }
}
