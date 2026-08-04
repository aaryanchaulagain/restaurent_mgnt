<?php

namespace Tests\Feature\Business;

use App\Models\Branch;
use App\Models\BranchInvitation;
use App\Models\BranchUser;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use App\Notifications\Branch\BranchInvitationNotification;
use App\Services\Business\BusinessHierarchyMigrator;
use App\Support\BranchInvitationStatuses;
use App\Support\BranchStatuses;
use App\Support\BusinessRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::flush();
    }

    public function test_owner_can_create_branch_with_manager_invitation(): void
    {
        Notification::fake();
        [$owner, $business] = $this->ownerWithBusiness('invite-create');

        Sanctum::actingAs($owner);
        $response = $this->postJson("/api/v1/businesses/{$business->public_id}/branches", [
            'name' => 'Dharan Branch',
            'code' => 'DHARAN',
            'status' => BranchStatuses::DRAFT,
            'invite_manager' => true,
            'manager_full_name' => 'Maya Manager',
            'manager_email' => 'maya.dharan@example.com',
            'manager_role' => BusinessRoles::BRANCH_MANAGER,
        ])->assertCreated();

        $branchPublicId = $response->json('data.branch.public_id');
        $this->assertNotNull($response->json('data.invitation.public_id'));
        $this->assertSame('pending', $response->json('data.invitation.status'));
        $this->assertNull($response->json('data.invitation.token_hash'));

        $branch = Branch::query()->where('public_id', $branchPublicId)->firstOrFail();
        $this->assertNotNull($branch->restaurant_id);
        $this->assertSame($business->id, $branch->business_id);

        $invitation = BranchInvitation::query()->where('branch_id', $branch->id)->firstOrFail();
        $this->assertSame($business->id, $invitation->business_id);
        $this->assertSame('maya.dharan@example.com', $invitation->email);
        $this->assertSame(64, strlen($invitation->token_hash));
        $this->assertFalse(str_contains(json_encode($response->json()), $invitation->token_hash));

        Notification::assertSentOnDemand(BranchInvitationNotification::class);
    }

    public function test_owner_can_create_branch_without_manager_invitation(): void
    {
        Notification::fake();
        [$owner, $business] = $this->ownerWithBusiness('invite-none');

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/businesses/{$business->public_id}/branches", [
            'name' => 'Biratnagar Branch',
            'code' => 'BRT',
        ])->assertCreated()
            ->assertJsonPath('data.invitation', null);

        Notification::assertNothingSent();
    }

    public function test_owner_cannot_invite_to_another_business(): void
    {
        [$ownerA] = $this->ownerWithBusiness('own-a');
        [, $businessB, $branchB] = $this->ownerWithBusiness('own-b');

        Sanctum::actingAs($ownerA);
        $this->postJson("/api/v1/businesses/{$businessB->public_id}/branches/{$branchB->public_id}/invitations", [
            'email' => 'intruder@example.com',
            'role' => BusinessRoles::BRANCH_MANAGER,
        ])->assertForbidden();
    }

    public function test_branch_manager_cannot_invite_another_branch_manager(): void
    {
        [$owner, $business, $branch] = $this->ownerWithBusiness('mgr-invite');
        $manager = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        app(\App\Services\Branch\BranchStaffService::class)->assign($branch, [
            'email' => $manager->email,
            'role' => BusinessRoles::BRANCH_MANAGER,
        ], $owner);

        Sanctum::actingAs($manager->fresh());
        $this->postJson("/api/v1/businesses/{$business->public_id}/branches/{$branch->public_id}/invitations", [
            'email' => 'peer@example.com',
            'role' => BusinessRoles::BRANCH_MANAGER,
        ])->assertStatus(403)
            ->assertJsonPath('code', 'BRANCH_INVITATION_ACCESS_DENIED');
    }

    public function test_new_user_can_accept_invitation_and_gets_branch_only_access(): void
    {
        Notification::fake();
        [$owner, $business, $branch] = $this->ownerWithBusiness('accept-new');
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/businesses/{$business->public_id}/branches/{$branch->public_id}/invitations", [
            'email' => 'new.manager@example.com',
            'full_name' => 'New Manager',
            'role' => BusinessRoles::BRANCH_MANAGER,
        ])->assertCreated();

        $invitation = BranchInvitation::query()->where('email', 'new.manager@example.com')->firstOrFail();
        $plain = 'plain-token-for-test-abcdefghijklmnopqrstuvwxyz0123456789extra';
        $invitation->forceFill([
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDay(),
            'status' => BranchInvitationStatuses::PENDING,
        ])->save();

        $this->clearAuth();

        $accept = $this->postJson("/api/v1/branch-invitations/{$plain}/accept", [
            'first_name' => 'New',
            'last_name' => 'Manager',
            'password' => 'Password1!Secure',
            'password_confirmation' => 'Password1!Secure',
        ])->assertOk();

        $userId = $accept->json('data.user.id');
        $user = User::query()->findOrFail($userId);
        $this->assertTrue(Hash::check('Password1!Secure', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('branch_users', [
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'role' => BusinessRoles::BRANCH_MANAGER,
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('business_users', [
            'business_id' => $business->id,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('restaurant_users', [
            'restaurant_id' => $branch->restaurant_id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $invitation->refresh();
        $this->assertSame(BranchInvitationStatuses::ACCEPTED, $invitation->status);
        $this->assertNotNull($invitation->accepted_at);

        // One-time use
        $this->postJson("/api/v1/branch-invitations/{$plain}/accept", [
            'password' => 'Password1!Secure',
            'password_confirmation' => 'Password1!Secure',
        ])->assertStatus(410);
    }

    public function test_expired_revoked_and_invalid_tokens_are_rejected(): void
    {
        [$owner, $business, $branch] = $this->ownerWithBusiness('token-sec');
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/businesses/{$business->public_id}/branches/{$branch->public_id}/invitations", [
            'email' => 'expire@example.com',
            'role' => BusinessRoles::BRANCH_MANAGER,
        ])->assertCreated();

        $invitation = BranchInvitation::query()->where('email', 'expire@example.com')->firstOrFail();
        $plain = str_repeat('a', 64);
        $invitation->forceFill(['token_hash' => hash('sha256', $plain)])->save();

        $this->getJson('/api/v1/branch-invitations/'.str_repeat('b', 64))
            ->assertStatus(404)
            ->assertJsonPath('code', 'BRANCH_INVITATION_TOKEN_INVALID');

        $invitation->forceFill([
            'status' => BranchInvitationStatuses::PENDING,
            'expires_at' => now()->subMinute(),
            'token_hash' => hash('sha256', $plain),
        ])->save();
        $this->getJson("/api/v1/branch-invitations/{$plain}")
            ->assertStatus(410)
            ->assertJsonPath('code', 'BRANCH_INVITATION_EXPIRED');

        // Fresh pending invitation for revoke coverage
        $plainRevoke = str_repeat('z', 64);
        $toRevoke = BranchInvitation::query()->create([
            'public_id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'invited_by_user_id' => $owner->id,
            'email' => 'revoke@example.com',
            'role' => BusinessRoles::BRANCH_MANAGER,
            'token_hash' => hash('sha256', $plainRevoke),
            'status' => BranchInvitationStatuses::PENDING,
            'expires_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/businesses/{$business->public_id}/branches/{$branch->public_id}/invitations/{$toRevoke->public_id}/revoke")
            ->assertOk();

        $toRevoke->refresh();
        $this->assertSame(BranchInvitationStatuses::REVOKED, $toRevoke->status);

        $this->getJson("/api/v1/branch-invitations/{$plainRevoke}")
            ->assertStatus(410)
            ->assertJsonPath('code', 'BRANCH_INVITATION_REVOKED');
    }

    public function test_email_mismatch_and_suspended_branch_block_accept(): void
    {
        [$owner, $business, $branch] = $this->ownerWithBusiness('mismatch');
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/businesses/{$business->public_id}/branches/{$branch->public_id}/invitations", [
            'email' => 'right@example.com',
            'role' => BusinessRoles::BRANCH_MANAGER,
        ])->assertCreated();

        $invitation = BranchInvitation::query()->where('email', 'right@example.com')->firstOrFail();
        $plain = str_repeat('c', 64);
        $invitation->forceFill([
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDay(),
        ])->save();

        $wrong = User::factory()->create([
            'email' => 'wrong@example.com',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($wrong);
        $this->postJson("/api/v1/branch-invitations/{$plain}/accept")
            ->assertStatus(403)
            ->assertJsonPath('code', 'BRANCH_INVITATION_EMAIL_MISMATCH');

        $branch->forceFill(['status' => BranchStatuses::SUSPENDED])->save();
        $right = User::factory()->create([
            'email' => 'right@example.com',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($right);
        $this->postJson("/api/v1/branch-invitations/{$plain}/accept")
            ->assertStatus(422)
            ->assertJsonPath('code', 'BRANCH_INVITATION_BRANCH_UNAVAILABLE');
    }

    public function test_resend_rotates_token_and_rate_limits(): void
    {
        Notification::fake();
        config([
            'suvakamana.branch_invitations.resend_cooldown_seconds' => 60,
            'suvakamana.branch_invitations.resend_daily_limit' => 2,
        ]);
        \Illuminate\Support\Facades\RateLimiter::clear('branch-invitation-resend');

        [$owner, $business, $branch] = $this->ownerWithBusiness('resend');
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/businesses/{$business->public_id}/branches/{$branch->public_id}/invitations", [
            'email' => 'resend@example.com',
            'role' => BusinessRoles::BRANCH_MANAGER,
        ])->assertCreated();

        $invitation = BranchInvitation::query()->where('email', 'resend@example.com')->firstOrFail();
        \Illuminate\Support\Facades\RateLimiter::clear('branch-invitation-resend:'.$invitation->id.':'.now()->format('Y-m-d'));
        $oldHash = $invitation->token_hash;

        $this->postJson("/api/v1/businesses/{$business->public_id}/branches/{$branch->public_id}/invitations/{$invitation->public_id}/resend")
            ->assertOk();
        $invitation->refresh();
        $this->assertNotSame($oldHash, $invitation->token_hash);
        $this->assertSame(1, $invitation->resend_count);

        $this->postJson("/api/v1/businesses/{$business->public_id}/branches/{$branch->public_id}/invitations/{$invitation->public_id}/resend")
            ->assertStatus(429)
            ->assertJsonPath('code', 'BRANCH_INVITATION_RESEND_LIMITED');
    }

    public function test_existing_user_accept_is_idempotent_and_preserves_other_roles(): void
    {
        [$owner, $business, $branch] = $this->ownerWithBusiness('exist-user');
        $existing = User::factory()->create([
            'email' => 'existing.mgr@example.com',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $customer = Role::query()->firstOrCreate(
            ['slug' => 'customer'],
            ['name' => 'Customer', 'guard' => 'web']
        );
        $existing->roles()->attach($customer->id);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/businesses/{$business->public_id}/branches/{$branch->public_id}/invitations", [
            'email' => 'existing.mgr@example.com',
            'role' => BusinessRoles::BRANCH_MANAGER,
        ])->assertCreated();

        $invitation = BranchInvitation::query()->where('email', 'existing.mgr@example.com')->firstOrFail();
        $plain = str_repeat('d', 64);
        $invitation->forceFill([
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDay(),
        ])->save();

        $this->actingAs($existing, 'web');
        Sanctum::actingAs($existing);
        $this->postJson("/api/v1/branch-invitations/{$plain}/accept")->assertOk();

        $this->assertSame(1, User::query()->where('email', 'existing.mgr@example.com')->count());
        $this->assertTrue($existing->fresh()->roles->contains('slug', 'customer'));
        $this->assertDatabaseHas('branch_users', [
            'user_id' => $existing->id,
            'branch_id' => $branch->id,
            'role' => BusinessRoles::BRANCH_MANAGER,
            'status' => 'active',
        ]);
    }

    public function test_branch_manager_cannot_access_sibling_via_header(): void
    {
        [$owner, $business, $branchA] = $this->ownerWithBusiness('iso-a');
        Sanctum::actingAs($owner);
        $created = $this->postJson("/api/v1/businesses/{$business->public_id}/branches", [
            'name' => 'Sibling',
            'code' => 'SIB',
        ])->assertCreated();
        $branchB = Branch::query()->where('public_id', $created->json('data.branch.public_id'))->firstOrFail();

        $plain = str_repeat('e', 64);
        $this->postJson("/api/v1/businesses/{$business->public_id}/branches/{$branchA->public_id}/invitations", [
            'email' => 'iso.manager@example.com',
            'role' => BusinessRoles::BRANCH_MANAGER,
        ])->assertCreated();
        $invitation = BranchInvitation::query()->where('email', 'iso.manager@example.com')->firstOrFail();
        $invitation->forceFill([
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDay(),
        ])->save();

        $this->clearAuth();
        $this->postJson("/api/v1/branch-invitations/{$plain}/accept", [
            'first_name' => 'Iso',
            'last_name' => 'Manager',
            'password' => 'Password1!Secure',
            'password_confirmation' => 'Password1!Secure',
        ])->assertOk();

        $manager = User::query()->where('email', 'iso.manager@example.com')->firstOrFail();
        Sanctum::actingAs($manager->fresh(['roles.permissions']));

        $this->getJson("/api/v1/businesses/{$business->public_id}/branches/{$branchA->public_id}")
            ->assertOk();
        $this->getJson("/api/v1/businesses/{$business->public_id}/branches/{$branchB->public_id}")
            ->assertForbidden();

        $this->getJson('/api/v1/restaurant/ping', [
            'X-Branch-Id' => $branchB->public_id,
        ])->assertForbidden()
            ->assertJsonPath('code', 'BRANCH_ACCESS_DENIED');
    }

    private function clearAuth(): void
    {
        foreach (array_keys(config('auth.guards', [])) as $guard) {
            \Illuminate\Support\Facades\Auth::guard($guard)->forgetUser();
        }
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    /** @return array{0: User, 1: Business, 2: Branch} */
    private function ownerWithBusiness(string $slug = 'invite-biz'): array
    {
        $this->seedRoles();
        $restaurant = Restaurant::query()->create([
            'public_id' => (string) Str::uuid(),
            'trading_name' => Str::headline($slug),
            'legal_business_name' => Str::headline($slug).' Pty Ltd',
            'slug' => $slug.'-'.Str::lower(Str::random(4)),
            'status' => 'active',
            'ownership_type' => 'third_party',
            'vendor_type' => 'restaurant',
            'accepting_orders' => true,
        ]);

        $owner = User::factory()->create([
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $role = Role::query()->where('slug', 'restaurant_owner')->firstOrFail();
        $owner->roles()->attach($role->id, ['restaurant_id' => $restaurant->id]);
        RestaurantUser::query()->create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $owner->id,
            'role_id' => $role->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $owner->load('roles.permissions');

        $result = app(BusinessHierarchyMigrator::class)->migrateRestaurant($restaurant->fresh());

        return [$owner->fresh(), $result['business'], $result['branch']->load('restaurant')];
    }

    private function seedRoles(): void
    {
        foreach ([
            'customer' => 'Customer',
            'restaurant_owner' => 'Restaurant Owner',
            'restaurant_manager' => 'Restaurant Manager',
            'restaurant_staff' => 'Restaurant Staff',
            'super_admin' => 'Super Admin',
        ] as $slug => $name) {
            Role::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'guard' => 'web']);
        }

        $perm = Permission::query()->firstOrCreate(
            ['slug' => 'view_restaurant_dashboard'],
            ['name' => 'View restaurant dashboard', 'guard' => 'web']
        );
        foreach (['restaurant_owner', 'restaurant_manager', 'restaurant_staff'] as $slug) {
            $role = Role::query()->where('slug', $slug)->first();
            if ($role && ! $role->permissions()->where('permissions.id', $perm->id)->exists()) {
                $role->permissions()->attach($perm->id);
            }
        }
    }
}
