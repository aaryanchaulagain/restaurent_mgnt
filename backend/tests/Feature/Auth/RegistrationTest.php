<?php

namespace Tests\Feature\Auth;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_customer_can_register(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Anisha',
            'last_name' => 'Rai',
            'email' => 'anisha@example.com',
            'phone' => '+9779801112233',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'terms' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'anisha@example.com')
            ->assertJsonMissingPath('data.user.password');

        $user = User::query()->where('email', 'anisha@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('Password1!', $user->password));
        $this->assertTrue($user->hasRole('customer'));
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'A',
            'last_name' => 'B',
            'email' => 'dup@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'terms' => true,
        ]);

        $response->assertStatus(422);
    }

    private function seedRoles(): void
    {
        $role = Role::query()->create(['slug' => 'customer', 'name' => 'Customer', 'guard' => 'web']);
        $permission = Permission::query()->create([
            'slug' => 'view_customer_profile',
            'name' => 'View customer profile',
        ]);
        $role->permissions()->attach($permission);
    }
}
