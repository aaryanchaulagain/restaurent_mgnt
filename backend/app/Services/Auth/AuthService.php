<?php

namespace App\Services\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly SessionTracker $sessionTracker,
        private readonly MfaService $mfaService,
    ) {}

    public function register(array $data, Request $request): User
    {
        return DB::transaction(function () use ($data, $request) {
            $user = User::query()->create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => Str::lower($data['email']),
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'status' => 'pending',
            ]);

            $role = Role::query()->where('slug', 'customer')->firstOrFail();
            $user->roles()->attach($role->id, ['restaurant_id' => null]);

            event(new Registered($user));

            $this->auditLogger->log('auth.registered', $user, $user, request: $request);

            Auth::guard('web')->login($user);
            if ($request->hasSession()) {
                $request->session()->regenerate();
            }
            $this->sessionTracker->start($user, $request);

            return $user->fresh(['roles.permissions', 'restaurantUsers.restaurant', 'mfaMethod']);
        });
    }

    /**
     * @return array{status: string, user?: User, message?: string}
     */
    public function attemptLogin(
        string $email,
        string $password,
        bool $remember,
        string $portal,
        Request $request,
    ): array
    {
        $email = Str::lower($email);
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            $this->recordAttempt($email, $user, false, 'invalid_credentials', $request);
            if ($user) {
                $this->incrementFailedAttempts($user);
            }
            $this->auditLogger->log('auth.login_failed', $user, $user, metadata: ['email' => $email], request: $request);

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if ($portal === 'super_admin' && ! $user->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'email' => ['This account cannot access the super admin portal.'],
            ]);
        }

        if ($portal !== 'super_admin' && $user->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'email' => ['Super administrators must use the super admin login page.'],
            ]);
        }

        if ($user->isSuspended()) {
            $this->recordAttempt($email, $user, false, 'suspended', $request);

            throw ValidationException::withMessages([
                'email' => ['This account has been suspended. Contact support for help.'],
            ]);
        }

        if ($user->isLocked()) {
            $this->recordAttempt($email, $user, false, 'locked', $request);

            throw ValidationException::withMessages([
                'email' => ['This account is temporarily locked. Try again later.'],
            ]);
        }

        if ($user->isSuperAdmin()
            && config('suvakamana.require_super_admin_mfa')
            && $user->hasConfirmedMfa()) {
            Session::put('mfa.pending_user_id', $user->id);
            Session::put('mfa.remember', $remember);
            $this->recordAttempt($email, $user, true, null, $request);

            return [
                'status' => 'mfa_required',
                'message' => 'Multi-factor authentication required.',
            ];
        }

        $this->completeLogin($user, $remember, $request);

        return [
            'status' => 'authenticated',
            'user' => $user->fresh(['roles.permissions', 'restaurantUsers.restaurant', 'mfaMethod']),
        ];
    }

    public function completeLogin(User $user, bool $remember, Request $request): void
    {
        Auth::guard('web')->login($user, $remember);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'status' => $user->status === 'locked' ? 'active' : $user->status,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        if ($user->status === 'pending' && $user->hasVerifiedEmail()) {
            $user->forceFill(['status' => 'active'])->save();
        }

        Session::forget(['mfa.pending_user_id', 'mfa.remember']);
        Session::put('mfa.verified', true);

        $this->sessionTracker->start($user, $request);
        $this->recordAttempt($user->email, $user, true, null, $request);
        $this->auditLogger->log('auth.login_success', $user, $user, request: $request);
    }

    public function completeMfaChallenge(string $code, Request $request, bool $recovery = false): User
    {
        $userId = Session::get('mfa.pending_user_id');
        if (! $userId) {
            abort(422, 'No MFA challenge is pending.');
        }

        $user = User::query()->findOrFail($userId);
        $ok = $recovery
            ? $this->mfaService->verifyRecoveryCode($user, $code)
            : $this->mfaService->verifyCode($user, $code);

        if (! $ok) {
            $this->auditLogger->log('auth.mfa_failed', $user, $user, request: $request);
            throw ValidationException::withMessages([
                'code' => ['Invalid authentication code.'],
            ]);
        }

        $remember = (bool) Session::get('mfa.remember', false);
        $this->completeLogin($user, $remember, $request);

        return $user->fresh(['roles.permissions', 'restaurantUsers.restaurant', 'mfaMethod']);
    }

    public function logout(Request $request): void
    {
        $user = $request->user();
        if ($user) {
            $this->sessionTracker->revokeCurrent($user);
            $this->auditLogger->log('auth.logout', $user, $user, request: $request);
        }

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    public function logoutAll(Request $request): void
    {
        $user = $request->user();
        if ($user) {
            $this->sessionTracker->revokeAll($user, exceptCurrent: false);
            $this->auditLogger->log('auth.logout_all', $user, $user, request: $request);
        }

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    public function sendPasswordResetLink(string $email): string
    {
        $status = Password::sendResetLink(['email' => Str::lower($email)]);
        $this->auditLogger->log(
            'auth.password_reset_requested',
            User::query()->where('email', Str::lower($email))->first(),
            metadata: ['email' => Str::lower($email)],
        );

        return $status;
    }

    public function resetPassword(array $data, Request $request): string
    {
        return Password::reset(
            [
                'email' => Str::lower($data['email']),
                'password' => $data['password'],
                'password_confirmation' => $data['password_confirmation'] ?? $data['password'],
                'token' => $data['token'],
            ],
            function (User $user, string $password) use ($request) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                ])->save();

                $this->sessionTracker->revokeAll($user);
                event(new PasswordReset($user));
                $this->auditLogger->log('auth.password_changed', $user, $user, request: $request);
            }
        );
    }

    public function changePassword(User $user, string $current, string $new, Request $request): void
    {
        if (! Hash::check($current, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->forceFill(['password' => $new])->save();
        $this->sessionTracker->revokeAll($user, exceptCurrent: true);
        $this->auditLogger->log('auth.password_changed', $user, $user, request: $request);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $this->sessionTracker->start($user, $request);
    }

    public function markEmailVerified(User $user, Request $request): void
    {
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
            if ($user->status === 'pending') {
                $user->forceFill(['status' => 'active'])->save();
            }
            $this->auditLogger->log('auth.email_verified', $user, $user, request: $request);
        }
    }

    private function incrementFailedAttempts(User $user): void
    {
        $attempts = $user->failed_login_attempts + 1;
        $max = (int) config('suvakamana.login.max_attempts', 5);
        $lockMinutes = (int) config('suvakamana.login.lock_minutes', 15);

        $payload = ['failed_login_attempts' => $attempts];
        if ($attempts >= $max) {
            $payload['locked_until'] = now()->addMinutes($lockMinutes);
            $payload['status'] = 'locked';
        }

        $user->forceFill($payload)->save();
    }

    private function recordAttempt(
        string $email,
        ?User $user,
        bool $successful,
        ?string $reason,
        Request $request,
    ): void {
        DB::table('login_attempts')->insert([
            'email' => $email,
            'user_id' => $user?->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'successful' => $successful,
            'failure_reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
