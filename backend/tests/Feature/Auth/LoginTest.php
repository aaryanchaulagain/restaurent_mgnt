<?php

namespace Tests\Feature\Auth;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::query()->create(['slug' => 'customer', 'name' => 'Customer', 'guard' => 'web']);
        Role::query()->create(['slug' => 'super_admin', 'name' => 'Super Admin', 'guard' => 'web']);
    }

    public function test_valid_login(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'Password1!',
            'status' => 'active',
        ]);
        $user->roles()->attach(Role::query()->where('slug', 'customer')->first());

        $response = $this->postJson('/api/auth/login', [
            'email' => 'login@example.com',
            'password' => 'Password1!',
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, $user->fresh()->failed_login_attempts);
    }

    public function test_invalid_credentials(): void
    {
        User::factory()->create(['email' => 'login@example.com', 'password' => 'Password1!']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'login@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
    }

    public function test_super_admin_must_use_super_admin_portal(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'Password1!',
            'status' => 'active',
        ]);
        $user->roles()->attach(Role::query()->where('slug', 'super_admin')->first());

        $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'Password1!',
            'portal' => 'standard',
        ])->assertUnprocessable()
            ->assertJsonFragment(['Super administrators must use the super admin login page.']);

        $this->assertGuest();
    }

    public function test_non_admin_cannot_use_super_admin_portal(): void
    {
        $user = User::factory()->create([
            'email' => 'customer@example.com',
            'password' => 'Password1!',
            'status' => 'active',
        ]);
        $user->roles()->attach(Role::query()->where('slug', 'customer')->first());

        $this->postJson('/api/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'Password1!',
            'portal' => 'super_admin',
        ])->assertUnprocessable()
            ->assertJsonFragment(['This account cannot access the super admin portal.']);

        $this->assertGuest();
    }

    public function test_super_admin_can_use_super_admin_portal(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'Password1!',
            'status' => 'active',
        ]);
        $user->roles()->attach(Role::query()->where('slug', 'super_admin')->first());

        $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'Password1!',
            'portal' => 'super_admin',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertAuthenticatedAs($user);
    }

    public function test_suspended_account_rejected(): void
    {
        User::factory()->create([
            'email' => 'suspended@example.com',
            'password' => 'Password1!',
            'status' => 'suspended',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'suspended@example.com',
            'password' => 'Password1!',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['This account has been suspended. Contact support for help.']);
    }

    public function test_failed_attempts_lock_account(): void
    {
        $user = User::factory()->create([
            'email' => 'lock@example.com',
            'password' => 'Password1!',
            'status' => 'active',
            'failed_login_attempts' => 0,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'lock@example.com',
                'password' => 'bad',
            ]);
        }

        $user->refresh();
        $this->assertSame(5, $user->failed_login_attempts);
        $this->assertNotNull($user->locked_until);
        $this->assertSame('locked', $user->status);
    }
}
