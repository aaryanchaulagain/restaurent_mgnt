<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Auth\MfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PasswordAndSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_returns_generic_response(): void
    {
        Notification::fake();
        Role::query()->create(['slug' => 'customer', 'name' => 'Customer', 'guard' => 'web']);
        User::factory()->create(['email' => 'reset@example.com']);

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'reset@example.com',
        ]);

        $response->assertOk()->assertJsonPath(
            'message',
            'If an account exists for that email, a password reset link has been sent.',
        );
    }

    public function test_password_reset_with_valid_token(): void
    {
        Role::query()->create(['slug' => 'customer', 'name' => 'Customer', 'guard' => 'web']);
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $token = Password::createToken($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => 'reset@example.com',
            'token' => $token,
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('NewPassword1!', $user->fresh()->password));
    }

    public function test_sessions_can_be_listed_and_revoked(): void
    {
        Role::query()->create(['slug' => 'customer', 'name' => 'Customer', 'guard' => 'web']);
        $user = User::factory()->create([
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::query()->first());

        $this->actingAs($user);
        $this->withSession(['tracked_session_key' => 'abc123']);

        UserSession::query()->create([
            'user_id' => $user->id,
            'session_key' => 'abc123',
            'device_label' => 'Current',
            'last_activity_at' => now(),
        ]);
        $other = UserSession::query()->create([
            'user_id' => $user->id,
            'session_key' => 'other456',
            'device_label' => 'Other',
            'last_activity_at' => now(),
        ]);

        $this->getJson('/api/auth/sessions')->assertOk()->assertJsonCount(2, 'data.sessions');
        $this->deleteJson('/api/auth/sessions/'.$other->id)->assertOk();
        $this->assertNotNull($other->fresh()->revoked_at);
    }

    public function test_mfa_setup_confirm_and_challenge(): void
    {
        Role::query()->create(['slug' => 'super_admin', 'name' => 'Super Admin', 'guard' => 'web']);
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'Password1!',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::query()->where('slug', 'super_admin')->first());

        $this->actingAs($user);
        $setup = $this->postJson('/api/auth/mfa/setup')->assertOk();
        $secret = $setup->json('data.secret');

        $google2fa = new \PragmaRX\Google2FA\Google2FA;
        $code = $google2fa->getCurrentOtp($secret);

        $confirm = $this->postJson('/api/auth/mfa/confirm', ['code' => $code]);
        $confirm->assertOk()->assertJsonStructure(['data' => ['recovery_codes']]);

        $this->postJson('/api/auth/logout');

        $login = $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'Password1!',
            'portal' => 'super_admin',
        ]);
        $login->assertOk()->assertJsonPath('data.mfa_required', true);

        $otp = $google2fa->getCurrentOtp($secret);
        $this->postJson('/api/auth/mfa/challenge', ['code' => $otp])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'admin@example.com');
    }
}
