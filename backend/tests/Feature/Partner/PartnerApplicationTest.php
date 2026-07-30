<?php

namespace Tests\Feature\Partner;

use App\Enums\Partner\ApplicationStatus;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantApplication;
use App\Models\RestaurantCommissionAgreement;
use App\Models\RestaurantDocument;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use App\Support\Abn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartnerApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Storage::fake('local');
    }

    public function test_guest_cannot_create_application(): void
    {
        $this->postJson('/api/v1/partner/applications')->assertUnauthorized();
    }

    public function test_customer_can_create_and_update_draft(): void
    {
        $user = $this->customer();
        Sanctum::actingAs($user);

        $create = $this->postJson('/api/v1/partner/applications')->assertCreated();
        $publicId = $create->json('data.application.public_id');

        $this->patchJson('/api/v1/partner/applications/'.$publicId, [
            'version' => 1,
            'legal_business_name' => 'Harbour Spice Pty Ltd',
            'trading_name' => 'Harbour Spice',
            'business_type' => 'company',
            'abn' => '51 824 753 556',
            'business_email' => 'ops@harbourspice.test',
            'business_phone' => '+61400000000',
            'description' => 'Modern spice kitchen',
            'primary_contact_name' => 'Test User',
            'primary_contact_email' => $user->email,
            'primary_contact_phone' => '+61411111111',
            'cuisine_summary' => 'Indian',
            'service_type' => 'delivery_and_pickup',
            'address' => [
                'address_line_1' => '10 Test St',
                'suburb' => 'Sydney',
                'state' => 'NSW',
                'postcode' => '2000',
            ],
        ])->assertOk()
            ->assertJsonPath('data.application.abn_raw', '51824753556')
            ->assertJsonPath('data.application.version', 2);
    }

    public function test_user_cannot_access_another_application(): void
    {
        $owner = $this->customer();
        $other = $this->customer('other@example.com');
        $app = $this->makeApplication($owner, ApplicationStatus::Draft);

        Sanctum::actingAs($other);
        $this->getJson('/api/v1/partner/applications/'.$app->public_id)->assertForbidden();
    }

    public function test_invalid_abn_is_rejected(): void
    {
        $user = $this->customer();
        $app = $this->makeApplication($user, ApplicationStatus::Draft);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/partner/applications/'.$app->public_id, [
            'version' => 1,
            'abn' => '12345678901',
        ])->assertStatus(422);
    }

    public function test_incomplete_draft_cannot_be_submitted(): void
    {
        $user = $this->customer();
        $app = $this->makeApplication($user, ApplicationStatus::Draft);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/partner/applications/'.$app->public_id.'/submit', [
            'terms' => true,
            'confirm_accuracy' => true,
        ])->assertStatus(422);
    }

    public function test_document_upload_validation_and_ownership(): void
    {
        $user = $this->customer();
        $app = $this->makeApplication($user, ApplicationStatus::Draft);
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->create('licence.pdf', 100, 'application/pdf');
        $this->post('/api/v1/partner/applications/'.$app->public_id.'/documents', [
            'document_type' => 'food_business_licence',
            'document' => $file,
        ], $this->defaultHeaders())->assertCreated();

        $exe = UploadedFile::fake()->create('bad.exe', 100, 'application/octet-stream');
        $this->post('/api/v1/partner/applications/'.$app->public_id.'/documents', [
            'document_type' => 'other',
            'document' => $exe,
        ], $this->defaultHeaders())->assertStatus(422);

        $other = $this->customer('intruder@example.com');
        $doc = $app->documents()->first();
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/partner/applications/'.$app->public_id.'/documents/'.$doc->id.'/download')
            ->assertForbidden();
    }

    public function test_approval_creates_restaurant_and_is_idempotent(): void
    {
        [$applicant, $admin, $app] = $this->readyForApproval();

        Sanctum::actingAs($admin);
        session(['mfa.verified' => true]);

        $this->postJson('/api/v1/admin/restaurant-applications/'.$app->public_id.'/approve', [
            'password' => 'Password1!',
        ])->assertOk()
            ->assertJsonPath('data.application.status', 'approved');

        $this->assertDatabaseHas('restaurants', [
            'trading_name' => $app->trading_name,
            'status' => 'pending_setup',
        ]);
        $this->assertTrue($applicant->fresh()->hasRole('restaurant_owner'));
        $this->assertDatabaseHas('restaurant_users', [
            'user_id' => $applicant->id,
            'status' => 'active',
        ]);

        $restaurantCount = Restaurant::query()->count();
        $this->postJson('/api/v1/admin/restaurant-applications/'.$app->public_id.'/approve', [
            'password' => 'Password1!',
        ])->assertOk();
        $this->assertSame($restaurantCount, Restaurant::query()->count());
    }

    public function test_internal_notes_hidden_from_applicant(): void
    {
        $user = $this->customer();
        $admin = $this->admin();
        $app = $this->makeApplication($user, ApplicationStatus::UnderReview);
        $app->notes()->create([
            'author_user_id' => $admin->id,
            'note' => 'Internal risk flag',
            'visibility' => 'internal',
        ]);
        $app->notes()->create([
            'author_user_id' => $admin->id,
            'note' => 'Please update licence',
            'visibility' => 'applicant_visible',
        ]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/partner/applications/'.$app->public_id)->assertOk();
        $notes = collect($response->json('data.application.notes'));
        $this->assertTrue($notes->contains(fn ($n) => $n['note'] === 'Please update licence'));
        $this->assertFalse($notes->contains(fn ($n) => $n['note'] === 'Internal risk flag'));
    }

    public function test_abn_helper_validates_checksum(): void
    {
        $this->assertTrue(Abn::isValid('51824753556'));
        $this->assertFalse(Abn::isValid('12345678901'));
        $this->assertSame('51824753556', Abn::normalize('51 824 753 556'));
    }

    private function readyForApproval(): array
    {
        $applicant = $this->customer();
        $admin = $this->admin();
        $app = $this->makeApplication($applicant, ApplicationStatus::UnderReview, complete: true);

        foreach (config('partner.required_documents') as $type) {
            RestaurantDocument::query()->create([
                'application_id' => $app->id,
                'document_type' => $type,
                'original_name' => $type.'.pdf',
                'storage_path' => 'restaurant-documents/test/'.$type.'.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 100,
                'status' => 'verified',
                'uploaded_by' => $applicant->id,
                'verified_by' => $admin->id,
                'verified_at' => now(),
            ]);
        }

        RestaurantCommissionAgreement::query()->create([
            'application_id' => $app->id,
            'commission_type' => 'percentage',
            'commission_rate' => '12.50',
            'fixed_fee_cents' => 0,
            'settlement_frequency' => 'weekly',
            'effective_from' => now()->toDateString(),
            'status' => 'offered',
            'created_by' => $admin->id,
            'terms_version' => '2026-07-01',
        ]);

        return [$applicant, $admin, $app->fresh()];
    }

    private function makeApplication(User $user, ApplicationStatus $status, bool $complete = false): RestaurantApplication
    {
        $app = RestaurantApplication::query()->create([
            'public_id' => (string) Str::uuid(),
            'applicant_user_id' => $user->id,
            'status' => $status,
            'legal_business_name' => $complete ? 'Harbour Spice Pty Ltd' : null,
            'trading_name' => $complete ? 'Harbour Spice' : null,
            'business_type' => $complete ? 'company' : null,
            'abn' => $complete ? '51824753556' : null,
            'business_email' => $complete ? 'ops@harbourspice.test' : null,
            'business_phone' => $complete ? '+61400000000' : null,
            'description' => $complete ? 'Modern spice kitchen' : null,
            'primary_contact_name' => $complete ? 'Test User' : null,
            'primary_contact_email' => $complete ? $user->email : null,
            'primary_contact_phone' => $complete ? '+61411111111' : null,
            'cuisine_summary' => $complete ? 'Indian' : null,
            'service_type' => $complete ? 'delivery_and_pickup' : null,
            'submitted_at' => $status === ApplicationStatus::Draft ? null : now(),
            'version' => 1,
        ]);

        if ($complete) {
            $app->addresses()->create([
                'address_type' => 'physical',
                'address_line_1' => '10 Test St',
                'suburb' => 'Sydney',
                'state' => 'NSW',
                'postcode' => '2000',
                'country' => 'AU',
                'is_primary' => true,
            ]);
        }

        return $app;
    }

    private function customer(string $email = 'partner-customer@example.com'): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => 'Password1!',
        ]);
        $user->roles()->attach(Role::query()->where('slug', 'customer')->first());

        return $user;
    }

    private function admin(): User
    {
        $user = User::factory()->create([
            'email' => 'partner-admin@example.com',
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => 'Password1!',
        ]);
        $user->roles()->attach(Role::query()->where('slug', 'super_admin')->first());

        return $user;
    }

    private function seedRoles(): void
    {
        foreach (config('suvakamana.permissions') as $slug) {
            Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]);
        }

        $map = [
            'customer' => [
                'submit_restaurant_application',
                'view_own_restaurant_application',
                'edit_own_restaurant_application',
                'withdraw_own_restaurant_application',
                'view_customer_profile',
            ],
            'restaurant_owner' => ['view_restaurant_dashboard'],
            'restaurant_staff' => ['view_restaurant_dashboard', 'view_orders'],
            'super_admin' => [
                'view_super_admin_dashboard',
                'view_restaurant_applications',
                'review_restaurant_applications',
                'request_application_changes',
                'approve_restaurant_applications',
                'reject_restaurant_applications',
                'verify_restaurant_documents',
                'manage_commission_agreements',
                'assign_application_reviewers',
                'manage_applications',
            ],
        ];

        foreach ($map as $role => $perms) {
            $r = Role::query()->firstOrCreate(['slug' => $role], ['name' => $role, 'guard' => 'web']);
            $r->permissions()->sync(Permission::query()->whereIn('slug', $perms)->pluck('id'));
        }
    }

    private function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Origin' => 'http://localhost:3000',
        ];
    }
}
