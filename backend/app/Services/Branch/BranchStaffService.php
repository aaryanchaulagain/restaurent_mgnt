<?php

namespace App\Services\Branch;

use App\Events\Branch\BranchStaffAssigned;
use App\Events\Branch\BranchStaffRemoved;
use App\Events\Branch\BranchStaffRoleChanged;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Services\Business\LegacyRestaurantRoleSynchronizer;
use App\Support\BusinessRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BranchStaffService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly LegacyRestaurantRoleSynchronizer $legacySync,
    ) {}

    /**
     * @param  array{first_name?: string, last_name?: string, email: string, password?: string|null, phone?: string|null, role: string}  $data
     * @return array{user: User, assignment: BranchUser, temporary_password: string|null}
     */
    public function assign(Branch $branch, array $data, User $actor, ?Request $request = null): array
    {
        $role = $data['role'];
        if (! in_array($role, BusinessRoles::branchLevel(), true)) {
            throw ValidationException::withMessages(['role' => ['Invalid branch role.']]);
        }

        $this->assertActorMayAssign($branch, $actor, $role);

        return DB::transaction(function () use ($branch, $data, $role, $actor, $request) {
            [$user, $temporaryPassword] = $this->resolveUser($data);
            $existing = BranchUser::query()
                ->where('branch_id', $branch->id)
                ->where('user_id', $user->id)
                ->first();

            $oldRole = $existing?->role;

            $assignment = BranchUser::query()->updateOrCreate(
                [
                    'branch_id' => $branch->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $role,
                    'status' => 'active',
                    'invited_by' => $actor->id,
                    'joined_at' => $existing?->joined_at ?? now(),
                ]
            );

            // Remove duplicate role rows if unique was (branch,user,role).
            BranchUser::query()
                ->where('branch_id', $branch->id)
                ->where('user_id', $user->id)
                ->where('id', '!=', $assignment->id)
                ->delete();

            $this->legacySync->syncBranchAssignment($branch, $user, $role, $actor, $request);

            $this->auditLogger->log(
                'branch.staff_assigned',
                $actor,
                $user,
                restaurantId: $branch->restaurant_id,
                metadata: [
                    'business_id' => $branch->business_id,
                    'branch_id' => $branch->id,
                    'role' => $role,
                    'old_role' => $oldRole,
                ],
                request: $request,
            );

            if ($oldRole && $oldRole !== $role) {
                event(new BranchStaffRoleChanged($branch, $user, $oldRole, $role, $actor));
            } else {
                event(new BranchStaffAssigned($branch, $user, $role, $actor));
            }

            return [
                'user' => $user->fresh(),
                'assignment' => $assignment->fresh(['user']),
                'temporary_password' => $temporaryPassword,
            ];
        });
    }

    public function remove(Branch $branch, User $user, User $actor, ?Request $request = null): void
    {
        $this->assertActorMayManageStaff($branch, $actor);

        DB::transaction(function () use ($branch, $user, $actor, $request) {
            $assignment = BranchUser::query()
                ->where('branch_id', $branch->id)
                ->where('user_id', $user->id)
                ->first();

            if (! $assignment) {
                return;
            }

            if ($assignment->role === BusinessRoles::BRANCH_MANAGER) {
                $managerCount = BranchUser::query()
                    ->where('branch_id', $branch->id)
                    ->where('status', 'active')
                    ->where('role', BusinessRoles::BRANCH_MANAGER)
                    ->count();
                if ($managerCount <= 1) {
                    throw new \App\Exceptions\BranchInvitationException(
                        'LAST_BRANCH_MANAGER_REQUIRED',
                        'Cannot remove the last branch manager.',
                        422,
                    );
                }
            }

            $assignment->forceFill(['status' => 'removed'])->save();

            $this->legacySync->removeBranchAssignment($branch, $user, $actor, $request);

            $this->auditLogger->log(
                'branch.staff_removed',
                $actor,
                $user,
                restaurantId: $branch->restaurant_id,
                metadata: [
                    'business_id' => $branch->business_id,
                    'branch_id' => $branch->id,
                    'role' => $assignment->role,
                ],
                request: $request,
            );

            event(new BranchStaffRemoved($branch, $user, $actor));
        });
    }

    /**
     * @param  array{first_name?: string, last_name?: string, email: string, password?: string|null, phone?: string|null, role: string}  $data
     * @return array{user: User, assignment: BusinessUser, temporary_password: string|null}
     */
    public function assignBusinessUser(Business $business, array $data, User $actor, ?Request $request = null): array
    {
        $role = $data['role'];
        if (! in_array($role, BusinessRoles::businessAssignable(), true)) {
            throw ValidationException::withMessages(['role' => ['Invalid business role.']]);
        }

        if (! $actor->isSuperAdmin()) {
            $actorRole = BusinessUser::query()
                ->where('business_id', $business->id)
                ->where('user_id', $actor->id)
                ->where('status', 'active')
                ->value('role');
            if (! in_array($actorRole, BusinessRoles::businessManagers(), true)) {
                throw ValidationException::withMessages(['role' => ['Not allowed to manage business users.']]);
            }
        }

        return DB::transaction(function () use ($business, $data, $role, $actor, $request) {
            [$user, $temporaryPassword] = $this->resolveUser($data);

            $assignment = BusinessUser::query()->updateOrCreate(
                [
                    'business_id' => $business->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $role,
                    'status' => 'active',
                    'invited_by' => $actor->id,
                    'joined_at' => now(),
                ]
            );

            BusinessUser::query()
                ->where('business_id', $business->id)
                ->where('user_id', $user->id)
                ->where('id', '!=', $assignment->id)
                ->delete();

            if (in_array($role, BusinessRoles::businessManagers(), true)) {
                $this->legacySync->syncBusinessManagerAcrossBranches(
                    $business->id,
                    $user,
                    $role,
                    $actor,
                    $request,
                );
            }

            if ($role === BusinessRoles::BUSINESS_OWNER && ! $business->owner_user_id) {
                $business->forceFill(['owner_user_id' => $user->id])->save();
            }

            $this->auditLogger->log(
                'business.staff_assigned',
                $actor,
                $user,
                metadata: [
                    'business_id' => $business->id,
                    'role' => $role,
                ],
                request: $request,
            );

            return [
                'user' => $user->fresh(),
                'assignment' => $assignment->fresh(['user']),
                'temporary_password' => $temporaryPassword,
            ];
        });
    }

    public function removeBusinessUser(Business $business, User $user, User $actor, ?Request $request = null): void
    {
        if (! $actor->isSuperAdmin()) {
            $actorRole = BusinessUser::query()
                ->where('business_id', $business->id)
                ->where('user_id', $actor->id)
                ->where('status', 'active')
                ->value('role');
            if (! in_array($actorRole, BusinessRoles::businessManagers(), true)) {
                throw ValidationException::withMessages(['user' => ['Not allowed to manage business users.']]);
            }
        }

        DB::transaction(function () use ($business, $user, $actor, $request) {
            $assignment = BusinessUser::query()
                ->where('business_id', $business->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->first();

            if (! $assignment) {
                return;
            }

            if ($assignment->role === BusinessRoles::BUSINESS_OWNER) {
                $ownerCount = BusinessUser::query()
                    ->where('business_id', $business->id)
                    ->where('status', 'active')
                    ->where('role', BusinessRoles::BUSINESS_OWNER)
                    ->count();
                if ($ownerCount <= 1) {
                    throw ValidationException::withMessages([
                        'user' => ['Cannot remove the last business owner.'],
                    ]);
                }
            }

            $assignment->forceFill(['status' => 'removed'])->save();

            $this->legacySync->syncBusinessManagerAcrossBranches(
                $business->id,
                $user,
                $assignment->role,
                $actor,
                $request,
                'removed',
            );

            $this->auditLogger->log(
                'business.staff_removed',
                $actor,
                $user,
                metadata: [
                    'business_id' => $business->id,
                    'role' => $assignment->role,
                ],
                request: $request,
            );
        });
    }

    private function assertActorMayAssign(Branch $branch, User $actor, string $role): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        $businessRole = BusinessUser::query()
            ->where('business_id', $branch->business_id)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->value('role');

        if (in_array($businessRole, BusinessRoles::businessManagers(), true)) {
            return;
        }

        $branchRole = BranchUser::query()
            ->where('branch_id', $branch->id)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->value('role');

        if ($branchRole === BusinessRoles::BRANCH_MANAGER
            && in_array($role, BusinessRoles::branchManagerAssignable(), true)) {
            return;
        }

        throw ValidationException::withMessages(['role' => ['Not allowed to assign this branch role.']]);
    }

    private function assertActorMayManageStaff(Branch $branch, User $actor): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        $businessRole = BusinessUser::query()
            ->where('business_id', $branch->business_id)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->value('role');

        if (in_array($businessRole, BusinessRoles::businessManagers(), true)) {
            return;
        }

        $branchRole = BranchUser::query()
            ->where('branch_id', $branch->id)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->value('role');

        if ($branchRole === BusinessRoles::BRANCH_MANAGER) {
            return;
        }

        throw ValidationException::withMessages(['user' => ['Not allowed to manage branch staff.']]);
    }

    /**
     * @param  array{first_name?: string, last_name?: string, email: string, password?: string|null, phone?: string|null}  $data
     * @return array{0: User, 1: string|null}
     */
    private function resolveUser(array $data): array
    {
        $email = strtolower(trim($data['email']));
        $existing = User::query()->where('email', $email)->first();
        $temporaryPassword = null;

        if ($existing) {
            if ($existing->isSuperAdmin()) {
                throw ValidationException::withMessages(['email' => ['Cannot assign portal roles to a super admin.']]);
            }

            return [$existing, null];
        }

        $temporaryPassword = $data['password'] ?? Str::password(12);
        $user = User::query()->create([
            'first_name' => $data['first_name'] ?? 'Staff',
            'last_name' => $data['last_name'] ?? 'Member',
            'email' => $email,
            'phone' => $data['phone'] ?? null,
            'password' => $temporaryPassword,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        return [$user, $temporaryPassword];
    }
}
