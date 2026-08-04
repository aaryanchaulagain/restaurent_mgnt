<?php

namespace App\Services\Branch;

use App\Exceptions\BranchInvitationException;
use App\Models\Branch;
use App\Models\BranchInvitation;
use App\Models\BranchUser;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\User;
use App\Notifications\Branch\BranchInvitationNotification;
use App\Services\Auth\AuditLogger;
use App\Services\Business\LegacyRestaurantRoleSynchronizer;
use App\Support\BranchInvitationStatuses;
use App\Support\BranchStatuses;
use App\Support\BusinessRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BranchInvitationService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly LegacyRestaurantRoleSynchronizer $legacySync,
    ) {}

    /**
     * @param  array{email: string, full_name?: string|null, phone?: string|null, role?: string}  $data
     * @return array{invitation: BranchInvitation, plain_token: string}
     */
    public function create(Branch $branch, array $data, User $actor, ?Request $request = null): array
    {
        $this->assertBranchBusinessConsistent($branch);
        $this->assertActorMayInvite($branch, $actor, $data['role'] ?? BusinessRoles::BRANCH_MANAGER);

        $email = $this->normalizeEmail($data['email']);
        $role = $data['role'] ?? BusinessRoles::BRANCH_MANAGER;

        if (! in_array($role, BusinessRoles::branchLevel(), true)) {
            throw new BranchInvitationException(
                'BRANCH_INVITATION_ROLE_INVALID',
                'Invalid invitation role.',
                422,
            );
        }

        $this->assertNoDuplicatePending($branch, $email, $role);
        $this->assertNotAlreadyAssigned($branch, $email, $role);

        $plainToken = $this->generatePlainToken();

        $invitation = BranchInvitation::query()->create([
            'public_id' => (string) Str::uuid(),
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
            'invited_by_user_id' => $actor->id,
            'email' => $email,
            'full_name' => $data['full_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'role' => $role,
            'token_hash' => $this->hashToken($plainToken),
            'status' => BranchInvitationStatuses::PENDING,
            'expires_at' => now()->addHours((int) config('suvakamana.branch_invitations.expire_hours', 48)),
            'resend_count' => 0,
        ]);

        $this->auditLogger->log(
            'branch.invitation_created',
            $actor,
            $invitation,
            restaurantId: $branch->restaurant_id,
            metadata: [
                'business_id' => $branch->business_id,
                'branch_id' => $branch->id,
                'email' => $email,
                'role' => $role,
            ],
            request: $request,
        );

        return [
            'invitation' => $invitation->fresh(['branch', 'business', 'invitedBy']),
            'plain_token' => $plainToken,
        ];
    }

    /**
     * Dispatch email after the surrounding DB transaction has committed.
     */
    public function dispatchNotification(BranchInvitation $invitation, string $plainToken): void
    {
        $invitation->loadMissing(['branch', 'business', 'invitedBy']);

        DB::afterCommit(function () use ($invitation, $plainToken) {
            \Illuminate\Support\Facades\Notification::route('mail', $invitation->email)
                ->notify(new BranchInvitationNotification($invitation, $plainToken));
        });
    }

    /**
     * Create invitation and queue notification when not already inside a caller transaction.
     *
     * @param  array{email: string, full_name?: string|null, phone?: string|null, role?: string}  $data
     */
    public function invite(Branch $branch, array $data, User $actor, ?Request $request = null): BranchInvitation
    {
        return DB::transaction(function () use ($branch, $data, $actor, $request) {
            $result = $this->create($branch, $data, $actor, $request);
            $this->dispatchNotification($result['invitation'], $result['plain_token']);

            return $result['invitation'];
        });
    }

    public function resend(BranchInvitation $invitation, User $actor, ?Request $request = null): BranchInvitation
    {
        $invitation = $this->lockInvitation($invitation->id);
        $branch = $invitation->branch()->firstOrFail();

        $this->assertActorMayInvite($branch, $actor, $invitation->role);

        if ($branch->status === BranchStatuses::SUSPENDED && ! $actor->isSuperAdmin()) {
            throw new BranchInvitationException(
                'BRANCH_INVITATION_BRANCH_UNAVAILABLE',
                'Cannot resend an invitation for a suspended branch.',
                422,
            );
        }

        if (! $invitation->isPending() || $invitation->isExpired()) {
            if ($invitation->isPending() && $invitation->isExpired()) {
                $invitation->forceFill(['status' => BranchInvitationStatuses::EXPIRED])->save();
            }

            throw new BranchInvitationException(
                $invitation->isRevoked() ? 'BRANCH_INVITATION_REVOKED' : (
                    $invitation->isAccepted() ? 'BRANCH_INVITATION_ALREADY_ACCEPTED' : 'BRANCH_INVITATION_EXPIRED'
                ),
                'This invitation cannot be resent.',
                422,
            );
        }

        $this->assertResendAllowed($invitation);

        $plainToken = $this->generatePlainToken();
        $invitation->forceFill([
            'token_hash' => $this->hashToken($plainToken),
            'expires_at' => now()->addHours((int) config('suvakamana.branch_invitations.expire_hours', 48)),
            'resend_count' => $invitation->resend_count + 1,
            'last_resent_at' => now(),
        ])->save();

        $this->recordResendAttempt($invitation);

        $this->auditLogger->log(
            'branch.invitation_resent',
            $actor,
            $invitation,
            restaurantId: $branch->restaurant_id,
            metadata: [
                'business_id' => $invitation->business_id,
                'branch_id' => $invitation->branch_id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'resend_count' => $invitation->resend_count,
            ],
            request: $request,
        );

        $this->dispatchNotification($invitation->fresh(['branch', 'business', 'invitedBy']), $plainToken);

        return $invitation->fresh(['branch', 'business', 'invitedBy']);
    }

    public function revoke(BranchInvitation $invitation, User $actor, ?Request $request = null): BranchInvitation
    {
        $invitation = $this->lockInvitation($invitation->id);
        $branch = $invitation->branch()->firstOrFail();

        $this->assertActorMayInvite($branch, $actor, $invitation->role);

        if (! $invitation->isPending()) {
            throw new BranchInvitationException(
                $invitation->isAccepted() ? 'BRANCH_INVITATION_ALREADY_ACCEPTED' : (
                    $invitation->isRevoked() ? 'BRANCH_INVITATION_REVOKED' : 'BRANCH_INVITATION_EXPIRED'
                ),
                'Only pending invitations can be revoked.',
                422,
            );
        }

        $invitation->forceFill([
            'status' => BranchInvitationStatuses::REVOKED,
            'revoked_at' => now(),
        ])->save();

        $this->auditLogger->log(
            'branch.invitation_revoked',
            $actor,
            $invitation,
            restaurantId: $branch->restaurant_id,
            metadata: [
                'business_id' => $invitation->business_id,
                'branch_id' => $invitation->branch_id,
                'email' => $invitation->email,
                'role' => $invitation->role,
            ],
            request: $request,
        );

        return $invitation->fresh(['branch', 'business', 'invitedBy']);
    }

    /**
     * Public preview — never returns token hash.
     *
     * @return array{invitation: BranchInvitation, existing_user: bool}
     */
    public function previewByToken(string $plainToken): array
    {
        $invitation = $this->findPendingByToken($plainToken);

        return [
            'invitation' => $invitation->load(['branch:id,public_id,name,status,business_id', 'business:id,public_id,name,status']),
            'existing_user' => User::query()->where('email', $invitation->email)->exists(),
        ];
    }

    /**
     * @param  array{password?: string, password_confirmation?: string, first_name?: string, last_name?: string}  $data
     * @return array{user: User, invitation: BranchInvitation, branch: Branch}
     */
    public function acceptByToken(string $plainToken, array $data, ?User $authenticatedUser, ?Request $request = null): array
    {
        return DB::transaction(function () use ($plainToken, $data, $authenticatedUser, $request) {
            $invitation = $this->findPendingByToken($plainToken, lock: true);
            $branch = Branch::query()->lockForUpdate()->findOrFail($invitation->branch_id);
            $business = Business::query()->findOrFail($invitation->business_id);

            if ((int) $branch->business_id !== (int) $invitation->business_id) {
                throw new BranchInvitationException(
                    'BRANCH_BUSINESS_MISMATCH',
                    'Invitation business and branch do not match.',
                    422,
                );
            }

            if ($branch->status === BranchStatuses::SUSPENDED || $business->status === 'suspended') {
                throw new BranchInvitationException(
                    'BRANCH_INVITATION_BRANCH_UNAVAILABLE',
                    'This branch or business is not available for staff onboarding.',
                    422,
                );
            }

            $user = $this->resolveAcceptingUser($invitation, $data, $authenticatedUser, $request);

            $existing = BranchUser::query()
                ->where('branch_id', $branch->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing && $existing->status === 'active' && $existing->role !== $invitation->role) {
                if ($existing->role === BusinessRoles::BRANCH_MANAGER
                    && $invitation->role !== BusinessRoles::BRANCH_MANAGER) {
                    throw new BranchInvitationException(
                        'BRANCH_STAFF_ASSIGNMENT_EXISTS',
                        'This user is already a branch manager. Ask an owner to change the role.',
                        422,
                    );
                }
            }

            $assignment = BranchUser::query()->updateOrCreate(
                [
                    'branch_id' => $branch->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $invitation->role,
                    'status' => 'active',
                    'invited_by' => $invitation->invited_by_user_id,
                    'joined_at' => $existing?->joined_at ?? now(),
                ]
            );

            BranchUser::query()
                ->where('branch_id', $branch->id)
                ->where('user_id', $user->id)
                ->where('id', '!=', $assignment->id)
                ->delete();

            $this->legacySync->syncBranchAssignment(
                $branch,
                $user,
                $invitation->role,
                $invitation->invitedBy ?? $user,
                $request,
            );

        $invitation->forceFill([
            'status' => BranchInvitationStatuses::ACCEPTED,
            'accepted_at' => now(),
            'accepted_by_user_id' => $user->id,
        ])->save();

            $this->auditLogger->log(
                'branch.invitation_accepted',
                $user,
                $invitation,
                restaurantId: $branch->restaurant_id,
                metadata: [
                    'business_id' => $branch->business_id,
                    'branch_id' => $branch->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role,
                    'accepted_by_user_id' => $user->id,
                ],
                request: $request,
            );

            $this->auditLogger->log(
                'branch.staff_assigned',
                $user,
                $user,
                restaurantId: $branch->restaurant_id,
                metadata: [
                    'business_id' => $branch->business_id,
                    'branch_id' => $branch->id,
                    'role' => $invitation->role,
                    'via' => 'invitation',
                ],
                request: $request,
            );

            return [
                'user' => $user->fresh(['roles.permissions', 'restaurantUsers.restaurant', 'branchUsers', 'mfaMethod']),
                'invitation' => $invitation->fresh(),
                'branch' => $branch->fresh(['business', 'restaurant']),
            ];
        });
    }

    public function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    private function generatePlainToken(): string
    {
        return Str::random(64);
    }

    private function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    private function lockInvitation(int $id): BranchInvitation
    {
        return BranchInvitation::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function findPendingByToken(string $plainToken, bool $lock = false): BranchInvitation
    {
        if ($plainToken === '' || strlen($plainToken) < 32) {
            throw new BranchInvitationException(
                'BRANCH_INVITATION_TOKEN_INVALID',
                'This invitation link is invalid.',
                404,
            );
        }

        $query = BranchInvitation::query()->where('token_hash', $this->hashToken($plainToken));
        if ($lock) {
            $query->lockForUpdate();
        }

        $invitation = $query->first();
        if (! $invitation) {
            throw new BranchInvitationException(
                'BRANCH_INVITATION_TOKEN_INVALID',
                'This invitation link is invalid.',
                404,
            );
        }

        if ($invitation->isRevoked()) {
            throw new BranchInvitationException(
                'BRANCH_INVITATION_REVOKED',
                'This invitation has been revoked.',
                410,
            );
        }

        if ($invitation->isAccepted()) {
            throw new BranchInvitationException(
                'BRANCH_INVITATION_ALREADY_ACCEPTED',
                'This invitation has already been accepted.',
                410,
            );
        }

        if ($invitation->isExpired() || ($invitation->isPending() && $invitation->expires_at?->isPast())) {
            if ($invitation->isPending()) {
                $invitation->forceFill(['status' => BranchInvitationStatuses::EXPIRED])->save();
            }

            throw new BranchInvitationException(
                'BRANCH_INVITATION_EXPIRED',
                'This invitation has expired.',
                410,
            );
        }

        if (! $invitation->isPending()) {
            throw new BranchInvitationException(
                'BRANCH_INVITATION_TOKEN_INVALID',
                'This invitation link is invalid.',
                404,
            );
        }

        return $invitation;
    }

    /**
     * @param  array{password?: string, password_confirmation?: string, first_name?: string, last_name?: string}  $data
     */
    private function resolveAcceptingUser(
        BranchInvitation $invitation,
        array $data,
        ?User $authenticatedUser,
        ?Request $request,
    ): User {
        $existing = User::query()->where('email', $invitation->email)->first();

        if ($authenticatedUser) {
            if ($this->normalizeEmail($authenticatedUser->email) !== $invitation->email) {
                $this->auditLogger->log(
                    'branch.invitation_email_mismatch',
                    $authenticatedUser,
                    $invitation,
                    restaurantId: $invitation->branch?->restaurant_id,
                    metadata: [
                        'business_id' => $invitation->business_id,
                        'branch_id' => $invitation->branch_id,
                        'invited_email' => $invitation->email,
                    ],
                    request: $request,
                );

                throw new BranchInvitationException(
                    'BRANCH_INVITATION_EMAIL_MISMATCH',
                    'Sign in with the invited email address to accept this invitation.',
                    403,
                );
            }

            return $authenticatedUser;
        }

        if ($existing) {
            throw new BranchInvitationException(
                'BRANCH_INVITATION_EMAIL_MISMATCH',
                'An account already exists for this email. Sign in to accept the invitation.',
                403,
            );
        }

        $password = $data['password'] ?? null;
        if (! is_string($password) || $password === '') {
            throw ValidationException::withMessages([
                'password' => ['A password is required to create your account.'],
            ]);
        }

        $nameParts = $this->splitName($invitation->full_name, $data);

        $user = User::query()->create([
            'first_name' => $nameParts['first_name'],
            'last_name' => $nameParts['last_name'],
            'email' => $invitation->email,
            'phone' => $invitation->phone,
            'password' => $password,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->auditLogger->log(
            'branch.invitation_user_created',
            $user,
            $user,
            restaurantId: $invitation->branch?->restaurant_id,
            metadata: [
                'business_id' => $invitation->business_id,
                'branch_id' => $invitation->branch_id,
                'invitation_public_id' => $invitation->public_id,
            ],
            request: $request,
        );

        return $user;
    }

    /**
     * @param  array{first_name?: string, last_name?: string}  $data
     * @return array{first_name: string, last_name: string}
     */
    private function splitName(?string $fullName, array $data): array
    {
        if (! empty($data['first_name'])) {
            return [
                'first_name' => (string) $data['first_name'],
                'last_name' => (string) ($data['last_name'] ?? ''),
            ];
        }

        $fullName = trim((string) $fullName);
        if ($fullName === '') {
            return ['first_name' => 'Partner', 'last_name' => 'Manager'];
        }

        $parts = preg_split('/\s+/', $fullName, 2) ?: [$fullName];

        return [
            'first_name' => $parts[0],
            'last_name' => $parts[1] ?? '',
        ];
    }

    private function assertBranchBusinessConsistent(Branch $branch): void
    {
        if (! $branch->business_id) {
            throw new BranchInvitationException(
                'BRANCH_BUSINESS_MISMATCH',
                'Branch is missing a business.',
                422,
            );
        }
    }

    private function assertNoDuplicatePending(Branch $branch, string $email, string $role): void
    {
        $exists = BranchInvitation::query()
            ->where('branch_id', $branch->id)
            ->where('email', $email)
            ->where('role', $role)
            ->where('status', BranchInvitationStatuses::PENDING)
            ->where('expires_at', '>', now())
            ->exists();

        if ($exists) {
            throw new BranchInvitationException(
                'BRANCH_INVITATION_ALREADY_EXISTS',
                'A pending invitation already exists for this email and role.',
                422,
            );
        }
    }

    private function assertNotAlreadyAssigned(Branch $branch, string $email, string $role): void
    {
        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            return;
        }

        $assigned = BranchUser::query()
            ->where('branch_id', $branch->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('role', $role)
            ->exists();

        if ($assigned) {
            throw new BranchInvitationException(
                'BRANCH_STAFF_ASSIGNMENT_EXISTS',
                'This user is already assigned to the branch with this role.',
                422,
            );
        }
    }

    private function assertActorMayInvite(Branch $branch, User $actor, string $role): void
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
            if (! in_array($role, BusinessRoles::branchLevel(), true)) {
                throw new BranchInvitationException(
                    'BRANCH_INVITATION_ROLE_INVALID',
                    'Invalid invitation role.',
                    422,
                );
            }

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

        throw new BranchInvitationException(
            'BRANCH_INVITATION_ACCESS_DENIED',
            'You are not allowed to invite staff for this branch or role.',
            403,
        );
    }

    private function assertResendAllowed(BranchInvitation $invitation): void
    {
        $cooldown = (int) config('suvakamana.branch_invitations.resend_cooldown_seconds', 60);
        if ($invitation->last_resent_at && $invitation->last_resent_at->gt(now()->subSeconds($cooldown))) {
            throw new BranchInvitationException(
                'BRANCH_INVITATION_RESEND_LIMITED',
                'Please wait before resending this invitation.',
                429,
            );
        }

        $dailyLimit = (int) config('suvakamana.branch_invitations.resend_daily_limit', 10);
        $key = 'branch-invitation-resend:'.$invitation->id.':'.now()->format('Y-m-d');
        if (RateLimiter::tooManyAttempts($key, $dailyLimit)) {
            throw new BranchInvitationException(
                'BRANCH_INVITATION_RESEND_LIMITED',
                'Daily resend limit reached for this invitation.',
                429,
            );
        }
    }

    private function recordResendAttempt(BranchInvitation $invitation): void
    {
        $key = 'branch-invitation-resend:'.$invitation->id.':'.now()->format('Y-m-d');
        RateLimiter::hit($key, 86400);
    }
}
