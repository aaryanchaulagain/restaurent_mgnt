<?php

namespace App\Services\Partner;

use App\Enums\Partner\ApplicationStatus;
use App\Enums\Partner\RestaurantStatus;
use App\Models\Restaurant;
use App\Models\RestaurantAddress;
use App\Models\RestaurantApplication;
use App\Models\RestaurantApplicationNote;
use App\Models\RestaurantApplicationStatusHistory;
use App\Models\RestaurantCommissionAgreement;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use App\Notifications\Partner\ApplicationApprovedNotification;
use App\Notifications\Partner\ApplicationRejectedNotification;
use App\Notifications\Partner\ApplicationSubmittedNotification;
use App\Notifications\Partner\ChangesRequestedNotification;
use App\Notifications\Partner\CommissionOfferNotification;
use App\Notifications\Partner\OwnerAccessActivatedNotification;
use App\Notifications\Partner\ReviewStartedNotification;
use App\Services\Auth\AuditLogger;
use App\Support\Abn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RestaurantApplicationService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function createDraft(User $applicant, Request $request): RestaurantApplication
    {
        return DB::transaction(function () use ($applicant, $request) {
            $existing = RestaurantApplication::query()
                ->where('applicant_user_id', $applicant->id)
                ->whereIn('status', [
                    ApplicationStatus::Draft->value,
                    ApplicationStatus::Submitted->value,
                    ApplicationStatus::UnderReview->value,
                    ApplicationStatus::ChangesRequested->value,
                    ApplicationStatus::Resubmitted->value,
                ])
                ->first();

            if ($existing) {
                return $existing;
            }

            $application = RestaurantApplication::query()->create([
                'public_id' => (string) Str::uuid(),
                'applicant_user_id' => $applicant->id,
                'status' => ApplicationStatus::Draft,
                'primary_contact_name' => trim($applicant->first_name.' '.$applicant->last_name),
                'primary_contact_email' => $applicant->email,
                'primary_contact_phone' => $applicant->phone,
            ]);

            $this->recordHistory($application, null, ApplicationStatus::Draft, $applicant, 'Application draft created');
            $this->auditLogger->log('partner.application_created', $applicant, $application, request: $request);

            return $application->fresh(['addresses', 'documents']);
        });
    }

    public function updateDraft(RestaurantApplication $application, array $data, User $actor, Request $request): RestaurantApplication
    {
        if (! $application->status->isEditableByApplicant()) {
            throw ValidationException::withMessages([
                'status' => ['This application cannot be edited in its current status.'],
            ]);
        }

        $expectedVersion = $data['version'] ?? $application->version;
        if ((int) $expectedVersion !== (int) $application->version) {
            throw new ConflictHttpException('This application was updated elsewhere. Refresh and try again.');
        }

        return DB::transaction(function () use ($application, $data, $actor, $request) {
            $locked = RestaurantApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ((int) ($data['version'] ?? $locked->version) !== (int) $locked->version) {
                throw new ConflictHttpException('This application was updated elsewhere. Refresh and try again.');
            }

            if (array_key_exists('abn', $data)) {
                $data['abn'] = Abn::normalize($data['abn'] ?? null);
            }

            $address = $data['address'] ?? null;
            unset($data['address'], $data['version'], $data['terms'], $data['confirm_accuracy']);

            $old = $locked->only(array_keys($data));
            $locked->fill($data);
            $locked->version = $locked->version + 1;
            $locked->save();

            if (is_array($address)) {
                $this->upsertAddress($locked, $address);
            }

            $this->auditLogger->log(
                'partner.application_updated',
                $actor,
                $locked,
                oldValues: $old,
                newValues: $locked->only(array_keys($old)),
                request: $request,
            );

            return $locked->fresh(['addresses', 'documents', 'commissionAgreements']);
        });
    }

    public function submit(RestaurantApplication $application, User $actor, Request $request, bool $resubmit = false): RestaurantApplication
    {
        $this->assertSubmittable($application);

        return DB::transaction(function () use ($application, $actor, $request, $resubmit) {
            $locked = RestaurantApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $to = $resubmit ? ApplicationStatus::Resubmitted : ApplicationStatus::Submitted;

            if ($resubmit) {
                if ($from !== ApplicationStatus::ChangesRequested) {
                    throw ValidationException::withMessages(['status' => ['Only change-requested applications can be resubmitted.']]);
                }
            } else {
                if ($from !== ApplicationStatus::Draft) {
                    throw ValidationException::withMessages(['status' => ['Only draft applications can be submitted.']]);
                }
            }

            if (! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages(['status' => ['Invalid status transition.']]);
            }

            $locked->status = $to;
            $locked->submitted_at = now();
            $locked->terms_version = config('partner.terms_version');
            $locked->terms_accepted_at = now();
            $locked->terms_accepted_ip = $request->ip();
            $locked->version = $locked->version + 1;
            $locked->save();

            $this->recordHistory($locked, $from, $to, $actor, $resubmit ? 'Application resubmitted' : 'Application submitted');
            $this->auditLogger->log(
                $resubmit ? 'partner.application_resubmitted' : 'partner.application_submitted',
                $actor,
                $locked,
                request: $request,
            );

            $locked->applicant?->notify(new ApplicationSubmittedNotification($locked));

            return $locked->fresh(['addresses', 'documents']);
        });
    }

    public function withdraw(RestaurantApplication $application, User $actor, Request $request): RestaurantApplication
    {
        return DB::transaction(function () use ($application, $actor, $request) {
            $locked = RestaurantApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $to = ApplicationStatus::Withdrawn;

            if (! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages(['status' => ['This application cannot be withdrawn.']]);
            }

            $locked->status = $to;
            $locked->version = $locked->version + 1;
            $locked->save();

            $this->recordHistory($locked, $from, $to, $actor, 'Application withdrawn');
            $this->auditLogger->log('partner.application_withdrawn', $actor, $locked, request: $request);

            return $locked->fresh();
        });
    }

    public function startReview(RestaurantApplication $application, User $admin, Request $request): RestaurantApplication
    {
        return DB::transaction(function () use ($application, $admin, $request) {
            $locked = RestaurantApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            $to = ApplicationStatus::UnderReview;
            if (! in_array($from, [ApplicationStatus::Submitted, ApplicationStatus::Resubmitted, ApplicationStatus::Rejected], true)) {
                if ($from === ApplicationStatus::UnderReview) {
                    return $locked;
                }
                throw ValidationException::withMessages(['status' => ['Review cannot be started from this status.']]);
            }

            if (! $from->canTransitionTo($to) && $from !== ApplicationStatus::Resubmitted) {
                // resubmitted → under_review is allowed
            }
            if ($from === ApplicationStatus::Resubmitted && ! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages(['status' => ['Invalid status transition.']]);
            }

            $locked->status = $to;
            $locked->reviewed_at = now();
            $locked->reviewed_by = $admin->id;
            $locked->assigned_reviewer_id = $locked->assigned_reviewer_id ?: $admin->id;
            $locked->version = $locked->version + 1;
            $locked->save();

            $this->recordHistory($locked, $from, $to, $admin, 'Review started');
            $this->auditLogger->log('partner.review_started', $admin, $locked, request: $request);
            $locked->applicant?->notify(new ReviewStartedNotification($locked));

            return $locked->fresh();
        });
    }

    public function requestChanges(
        RestaurantApplication $application,
        User $admin,
        string $reason,
        array $items,
        Request $request,
    ): RestaurantApplication {
        return DB::transaction(function () use ($application, $admin, $reason, $items, $request) {
            $locked = RestaurantApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $to = ApplicationStatus::ChangesRequested;

            if ($from !== ApplicationStatus::UnderReview || ! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages(['status' => ['Changes can only be requested during review.']]);
            }

            $locked->status = $to;
            $locked->changes_requested_reason = $reason;
            $locked->changes_requested_items = $items;
            $locked->version = $locked->version + 1;
            $locked->save();

            RestaurantApplicationNote::query()->create([
                'application_id' => $locked->id,
                'author_user_id' => $admin->id,
                'note' => $reason,
                'visibility' => 'applicant_visible',
            ]);

            $this->recordHistory($locked, $from, $to, $admin, $reason, ['items' => $items]);
            $this->auditLogger->log('partner.changes_requested', $admin, $locked, metadata: ['items' => $items], request: $request);
            $locked->applicant?->notify(new ChangesRequestedNotification($locked));

            return $locked->fresh(['notes', 'statusHistory']);
        });
    }

    public function reject(
        RestaurantApplication $application,
        User $admin,
        string $category,
        string $reason,
        ?string $internalNote,
        Request $request,
    ): RestaurantApplication {
        return DB::transaction(function () use ($application, $admin, $category, $reason, $internalNote, $request) {
            $locked = RestaurantApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $to = ApplicationStatus::Rejected;

            if ($from !== ApplicationStatus::UnderReview || ! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages(['status' => ['Application cannot be rejected from this status.']]);
            }

            $locked->status = $to;
            $locked->rejected_at = now();
            $locked->rejection_category = $category;
            $locked->rejection_reason = $reason;
            $locked->reviewed_by = $admin->id;
            $locked->version = $locked->version + 1;
            $locked->save();

            if ($internalNote) {
                RestaurantApplicationNote::query()->create([
                    'application_id' => $locked->id,
                    'author_user_id' => $admin->id,
                    'note' => $internalNote,
                    'visibility' => 'internal',
                ]);
            }

            RestaurantApplicationNote::query()->create([
                'application_id' => $locked->id,
                'author_user_id' => $admin->id,
                'note' => $reason,
                'visibility' => 'applicant_visible',
            ]);

            $this->recordHistory($locked, $from, $to, $admin, $reason, ['category' => $category]);
            $this->auditLogger->log('partner.application_rejected', $admin, $locked, metadata: ['category' => $category], request: $request);
            $locked->applicant?->notify(new ApplicationRejectedNotification($locked));

            return $locked->fresh();
        });
    }

    public function approve(RestaurantApplication $application, User $admin, Request $request): RestaurantApplication
    {
        return DB::transaction(function () use ($application, $admin, $request) {
            $locked = RestaurantApplication::query()
                ->whereKey($application->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === ApplicationStatus::Approved) {
                return $locked->fresh(['restaurant', 'addresses', 'documents', 'commissionAgreements']);
            }

            if (! $locked->status->isApprovable() && $locked->status !== ApplicationStatus::UnderReview) {
                throw ValidationException::withMessages(['status' => ['Application is not approvable.']]);
            }

            if ($locked->status === ApplicationStatus::Resubmitted) {
                $locked->status = ApplicationStatus::UnderReview;
                $locked->save();
            }

            if ($locked->status !== ApplicationStatus::UnderReview) {
                throw ValidationException::withMessages(['status' => ['Start review before approving.']]);
            }

            $this->assertReadyForApproval($locked);

            $agreement = RestaurantCommissionAgreement::query()
                ->where('application_id', $locked->id)
                ->whereIn('status', ['offered', 'accepted'])
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $agreement) {
                throw ValidationException::withMessages([
                    'commission' => ['A valid commission agreement is required before approval.'],
                ]);
            }

            $from = $locked->status;
            $restaurant = $this->createOrUpdateRestaurantFromApplication($locked, $admin);
            $this->assignOwner($restaurant, $locked->applicant, $admin, $request);

            $agreement->forceFill([
                'restaurant_id' => $restaurant->id,
                'status' => $agreement->status === 'accepted' ? 'accepted' : 'offered',
            ])->save();

            $locked->addresses()->update(['restaurant_id' => $restaurant->id]);
            $locked->documents()->update(['restaurant_id' => $restaurant->id]);

            $locked->status = ApplicationStatus::Approved;
            $locked->restaurant_id = $restaurant->id;
            $locked->approved_at = now();
            $locked->reviewed_by = $admin->id;
            $locked->version = $locked->version + 1;
            $locked->save();

            $this->recordHistory($locked, $from, ApplicationStatus::Approved, $admin, 'Application approved');
            $this->auditLogger->log('partner.application_approved', $admin, $locked, restaurantId: $restaurant->id, request: $request);
            $this->auditLogger->log('partner.restaurant_created', $admin, $restaurant, restaurantId: $restaurant->id, request: $request);

            $locked->applicant?->notify(new ApplicationApprovedNotification($locked));
            $locked->applicant?->notify(new OwnerAccessActivatedNotification($restaurant));

            return $locked->fresh(['restaurant', 'addresses', 'documents', 'commissionAgreements']);
        });
    }

    public function upsertCommission(
        RestaurantApplication $application,
        User $admin,
        array $data,
        Request $request,
    ): RestaurantCommissionAgreement {
        return DB::transaction(function () use ($application, $admin, $data, $request) {
            RestaurantCommissionAgreement::query()
                ->where('application_id', $application->id)
                ->whereIn('status', ['draft', 'offered'])
                ->update(['status' => 'superseded']);

            $agreement = RestaurantCommissionAgreement::query()->create([
                'application_id' => $application->id,
                'restaurant_id' => $application->restaurant_id,
                'commission_type' => $data['commission_type'],
                'commission_rate' => $data['commission_rate'] ?? null,
                'fixed_fee_cents' => $data['fixed_fee_cents'] ?? 0,
                'processing_fee_responsibility' => $data['processing_fee_responsibility'] ?? null,
                'delivery_fee_responsibility' => $data['delivery_fee_responsibility'] ?? null,
                'discount_calculation_method' => $data['discount_calculation_method'] ?? null,
                'settlement_frequency' => $data['settlement_frequency'] ?? 'weekly',
                'effective_from' => $data['effective_from'] ?? now()->toDateString(),
                'effective_until' => $data['effective_until'] ?? null,
                'status' => $data['status'] ?? 'offered',
                'created_by' => $admin->id,
                'terms_version' => $data['terms_version'] ?? config('partner.terms_version'),
            ]);

            $this->auditLogger->log('partner.commission_created', $admin, $agreement, restaurantId: $application->restaurant_id, request: $request);
            $application->applicant?->notify(new CommissionOfferNotification($application, $agreement));

            return $agreement;
        });
    }

    public function acceptCommission(RestaurantApplication $application, User $user, Request $request): RestaurantCommissionAgreement
    {
        return DB::transaction(function () use ($application, $user, $request) {
            $agreement = RestaurantCommissionAgreement::query()
                ->where('application_id', $application->id)
                ->where('status', 'offered')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $agreement) {
                throw ValidationException::withMessages(['commission' => ['No commission offer is available.']]);
            }

            $agreement->forceFill([
                'status' => 'accepted',
                'accepted_by' => $user->id,
                'accepted_at' => now(),
            ])->save();

            $this->auditLogger->log('partner.commission_accepted', $user, $agreement, restaurantId: $application->restaurant_id, request: $request);

            return $agreement;
        });
    }

    public function addNote(RestaurantApplication $application, User $author, string $note, string $visibility): RestaurantApplicationNote
    {
        return RestaurantApplicationNote::query()->create([
            'application_id' => $application->id,
            'author_user_id' => $author->id,
            'note' => $note,
            'visibility' => $visibility,
        ]);
    }

    public function assignReviewer(RestaurantApplication $application, User $reviewer, User $admin, Request $request): RestaurantApplication
    {
        $application->forceFill(['assigned_reviewer_id' => $reviewer->id])->save();
        $this->auditLogger->log('partner.reviewer_assigned', $admin, $application, metadata: [
            'reviewer_id' => $reviewer->id,
        ], request: $request);

        return $application->fresh(['assignedReviewer']);
    }

    private function upsertAddress(RestaurantApplication $application, array $address): void
    {
        $payload = [
            'address_type' => $address['address_type'] ?? 'physical',
            'address_line_1' => $address['address_line_1'],
            'address_line_2' => $address['address_line_2'] ?? null,
            'suburb' => $address['suburb'],
            'state' => strtoupper($address['state']),
            'postcode' => $address['postcode'],
            'country' => $address['country'] ?? config('partner.default_country'),
            'is_primary' => true,
        ];

        $existing = $application->addresses()->where('address_type', $payload['address_type'])->first();
        if ($existing) {
            $existing->update($payload);
        } else {
            $application->addresses()->create($payload);
        }
    }

    private function assertSubmittable(RestaurantApplication $application): void
    {
        $required = config('partner.required_application_fields');
        $missing = [];
        foreach ($required as $field) {
            if (blank($application->{$field})) {
                $missing[] = $field;
            }
        }

        if (! $application->addresses()->exists()) {
            $missing[] = 'address';
        }

        if ($application->abn && ! Abn::isValid($application->abn)) {
            throw ValidationException::withMessages(['abn' => ['ABN is invalid.']]);
        }

        $requiredDocs = config('partner.required_documents');
        $uploadedTypes = $application->documents()->pluck('document_type')->all();
        foreach ($requiredDocs as $type) {
            if (! in_array($type, $uploadedTypes, true)) {
                $missing[] = 'document:'.$type;
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'application' => ['Application is incomplete.'],
                'missing' => $missing,
            ]);
        }
    }

    private function assertReadyForApproval(RestaurantApplication $application): void
    {
        $this->assertSubmittable($application);

        $requiredDocs = config('partner.required_documents');
        foreach ($requiredDocs as $type) {
            $ok = $application->documents()
                ->where('document_type', $type)
                ->where('status', 'verified')
                ->exists();
            if (! $ok) {
                throw ValidationException::withMessages([
                    'documents' => ["Required document {$type} must be verified before approval."],
                ]);
            }
        }
    }

    private function createOrUpdateRestaurantFromApplication(RestaurantApplication $application, User $admin): Restaurant
    {
        if ($application->restaurant_id) {
            $restaurant = Restaurant::query()->whereKey($application->restaurant_id)->lockForUpdate()->firstOrFail();
        } else {
            $restaurant = new Restaurant;
        }

        $slugBase = Str::slug($application->trading_name ?: $application->legal_business_name ?: 'restaurant');
        $slug = $restaurant->exists ? $restaurant->slug : $this->uniqueSlug($slugBase);

        $restaurant->fill([
            'public_id' => $restaurant->public_id ?: (string) Str::uuid(),
            'slug' => $slug,
            'legal_business_name' => $application->legal_business_name,
            'trading_name' => $application->trading_name,
            'description' => $application->description,
            'business_email' => $application->business_email,
            'business_phone' => $application->business_phone,
            'website_url' => $application->website_url,
            'abn' => $application->abn,
            'business_registration_number' => $application->business_registration_number,
            'status' => RestaurantStatus::PendingSetup,
            'verification_status' => 'verified',
            'timezone' => config('partner.default_timezone'),
            'currency' => config('partner.default_currency'),
            'approved_at' => now(),
            'approved_by' => $admin->id,
        ]);
        $restaurant->save();

        return $restaurant;
    }

    private function assignOwner(Restaurant $restaurant, User $applicant, User $admin, Request $request): void
    {
        $role = Role::query()->where('slug', 'restaurant_owner')->firstOrFail();

        if (! $applicant->roles()->where('roles.id', $role->id)->wherePivot('restaurant_id', $restaurant->id)->exists()) {
            $applicant->roles()->attach($role->id, ['restaurant_id' => $restaurant->id]);
        }

        RestaurantUser::query()->updateOrCreate(
            [
                'restaurant_id' => $restaurant->id,
                'user_id' => $applicant->id,
            ],
            [
                'role_id' => $role->id,
                'status' => 'active',
                'invited_by' => $admin->id,
                'joined_at' => now(),
            ],
        );

        $this->auditLogger->log(
            'partner.owner_assigned',
            $admin,
            $applicant,
            restaurantId: $restaurant->id,
            metadata: ['role' => 'restaurant_owner'],
            request: $request,
        );
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base ?: 'restaurant';
        $candidate = $slug;
        $i = 1;
        while (Restaurant::withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$i;
            $i++;
        }

        return $candidate;
    }

    private function recordHistory(
        RestaurantApplication $application,
        ?ApplicationStatus $from,
        ApplicationStatus $to,
        ?User $actor,
        ?string $reason = null,
        ?array $metadata = null,
    ): void {
        RestaurantApplicationStatusHistory::query()->create([
            'application_id' => $application->id,
            'old_status' => $from?->value,
            'new_status' => $to->value,
            'changed_by' => $actor?->id,
            'reason' => $reason,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
