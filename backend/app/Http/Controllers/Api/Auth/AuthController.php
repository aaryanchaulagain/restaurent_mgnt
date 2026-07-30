<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Auth\AuditLogger;
use App\Services\Auth\AuthService;
use App\Services\Auth\MfaService;
use App\Services\Auth\SessionTracker;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly MfaService $mfaService,
        private readonly SessionTracker $sessionTracker,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->authService->register($request->validated(), $request);
        $user->sendEmailVerificationNotification();

        return ApiResponse::success(
            data: ['user' => new UserResource($user)],
            message: 'Registration successful. Please verify your email.',
            status: 201,
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->attemptLogin(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->boolean('remember'),
            $request,
        );

        if ($result['status'] === 'mfa_required') {
            return ApiResponse::success(
                data: ['mfa_required' => true],
                message: $result['message'] ?? 'Multi-factor authentication required.',
            );
        }

        return ApiResponse::success(
            data: ['user' => new UserResource($result['user'])],
            message: 'Login successful.',
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request);

        return ApiResponse::success(message: 'Logged out successfully.');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $this->authService->logoutAll($request);

        return ApiResponse::success(message: 'Logged out of all sessions.');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['roles.permissions', 'restaurantUsers.restaurant', 'restaurantUsers.role', 'mfaMethod']);

        return ApiResponse::success(
            data: ['user' => new UserResource($user)],
        );
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        $this->authService->sendPasswordResetLink($request->string('email')->toString());

        return ApiResponse::success(
            message: 'If an account exists for that email, a password reset link has been sent.',
        );
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = $this->authService->resetPassword($data, $request);

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return ApiResponse::success(message: 'Password has been reset successfully.');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $this->authService->changePassword(
            $request->user(),
            $data['current_password'],
            $data['password'],
            $request,
        );

        return ApiResponse::success(message: 'Password updated successfully.');
    }

    public function sendVerification(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return ApiResponse::success(message: 'Email already verified.');
        }

        $request->user()->sendEmailVerificationNotification();
        $this->auditLogger->log('auth.verification_resent', $request->user(), $request->user(), request: $request);

        return ApiResponse::success(message: 'Verification link sent.');
    }

    public function verifyEmail(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::query()->findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return ApiResponse::error('Invalid verification link.', 403);
        }

        if (! $request->hasValidSignature()) {
            return ApiResponse::error('This verification link is invalid or has expired.', 403);
        }

        $this->authService->markEmailVerified($user, $request);

        return ApiResponse::success(message: 'Email verified successfully.');
    }

    public function sessions(Request $request): JsonResponse
    {
        $this->sessionTracker->touch($request);
        $currentKey = $request->hasSession()
            ? $request->session()->get('tracked_session_key')
            : null;

        $sessions = UserSession::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->orderByDesc('last_activity_at')
            ->get()
            ->map(fn (UserSession $session) => [
                'id' => $session->id,
                'device_label' => $session->device_label,
                'ip_address' => $session->ip_address,
                'last_activity_at' => $session->last_activity_at,
                'is_current' => $session->session_key === $currentKey,
                'created_at' => $session->created_at,
            ]);

        return ApiResponse::success(data: ['sessions' => $sessions]);
    }

    public function revokeSession(Request $request, int $sessionId): JsonResponse
    {
        $ok = $this->sessionTracker->revokeById($request->user(), $sessionId);
        if (! $ok) {
            return ApiResponse::error('Session not found.', 404);
        }

        $this->auditLogger->log('auth.session_revoked', $request->user(), request: $request, metadata: [
            'session_id' => $sessionId,
        ]);

        return ApiResponse::success(message: 'Session revoked.');
    }

    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $count = $this->sessionTracker->revokeAll($request->user(), exceptCurrent: true);
        $this->auditLogger->log('auth.sessions_revoked_others', $request->user(), request: $request, metadata: [
            'count' => $count,
        ]);

        return ApiResponse::success(message: 'Other sessions revoked.', data: ['revoked' => $count]);
    }

    public function mfaSetup(Request $request): JsonResponse
    {
        $setup = $this->mfaService->beginSetup($request->user());

        return ApiResponse::success(
            data: [
                'secret' => $setup['secret'],
                'qr_svg' => $setup['qr_svg'],
                'otpauth_url' => $setup['otpauth_url'],
            ],
            message: 'Scan the QR code with your authenticator app.',
        );
    }

    public function mfaConfirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $codes = $this->mfaService->confirmSetup($request->user(), $data['code']);
        if ($request->hasSession()) {
            $request->session()->put('mfa.verified', true);
        }

        return ApiResponse::success(
            data: ['recovery_codes' => $codes],
            message: 'MFA enabled successfully. Store your recovery codes safely.',
        );
    }

    public function mfaChallenge(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $user = $this->authService->completeMfaChallenge($data['code'], $request, recovery: false);

        return ApiResponse::success(
            data: ['user' => new UserResource($user)],
            message: 'MFA verification successful.',
        );
    }

    public function mfaRecovery(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $user = $this->authService->completeMfaChallenge($data['code'], $request, recovery: true);

        return ApiResponse::success(
            data: ['user' => new UserResource($user)],
            message: 'Recovery code accepted.',
        );
    }

    public function mfaRegenerateRecoveryCodes(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($request->string('password')->toString(), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => ['Password is incorrect.']]);
        }

        $codes = $this->mfaService->regenerateRecoveryCodes($request->user());

        return ApiResponse::success(
            data: ['recovery_codes' => $codes],
            message: 'Recovery codes regenerated.',
        );
    }

    public function mfaDisable(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($request->string('password')->toString(), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => ['Password is incorrect.']]);
        }

        $this->mfaService->disable($request->user());
        if ($request->hasSession()) {
            $request->session()->forget('mfa.verified');
        }

        return ApiResponse::success(message: 'MFA disabled.');
    }
}
