<?php

namespace App\Http\Resources\Partner;

use App\Support\Abn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\RestaurantApplication */
class RestaurantApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $includeInternal = (bool) ($this->additional['include_internal'] ?? false);

        return [
            'public_id' => $this->public_id,
            'status' => $this->status?->value ?? $this->status,
            'version' => $this->version,
            'legal_business_name' => $this->legal_business_name,
            'trading_name' => $this->trading_name,
            'business_type' => $this->business_type,
            'abn' => Abn::format($this->abn),
            'abn_raw' => $this->abn,
            'business_registration_number' => $this->business_registration_number,
            'business_email' => $this->business_email,
            'business_phone' => $this->business_phone,
            'website_url' => $this->website_url,
            'description' => $this->description,
            'primary_contact_name' => $this->primary_contact_name,
            'primary_contact_email' => $this->primary_contact_email,
            'primary_contact_phone' => $this->primary_contact_phone,
            'cuisine_summary' => $this->cuisine_summary,
            'service_type' => $this->service_type,
            'expected_monthly_orders' => $this->expected_monthly_orders,
            'current_delivery_method' => $this->current_delivery_method,
            'location_count' => $this->location_count,
            'referral_source' => $this->referral_source,
            'submitted_at' => $this->submitted_at,
            'reviewed_at' => $this->reviewed_at,
            'approved_at' => $this->approved_at,
            'rejected_at' => $this->rejected_at,
            'rejection_category' => $this->rejection_category,
            'rejection_reason' => $this->rejection_reason,
            'changes_requested_reason' => $this->changes_requested_reason,
            'changes_requested_items' => $this->changes_requested_items,
            'terms_version' => $this->terms_version,
            'terms_accepted_at' => $this->terms_accepted_at,
            'restaurant_public_id' => $this->restaurant?->public_id,
            'applicant' => $this->whenLoaded('applicant', fn () => [
                'id' => $this->applicant->id,
                'name' => $this->applicant->name,
                'email' => $this->applicant->email,
            ]),
            'assigned_reviewer' => $this->whenLoaded('assignedReviewer', fn () => $this->assignedReviewer ? [
                'id' => $this->assignedReviewer->id,
                'name' => $this->assignedReviewer->name,
                'email' => $this->assignedReviewer->email,
            ] : null),
            'addresses' => RestaurantAddressResource::collection($this->whenLoaded('addresses')),
            'documents' => RestaurantDocumentResource::collection($this->whenLoaded('documents')),
            'status_history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($h) => [
                'old_status' => $h->old_status,
                'new_status' => $h->new_status,
                'reason' => $h->reason,
                'created_at' => $h->created_at,
            ])),
            'notes' => $this->whenLoaded('notes', function () use ($includeInternal) {
                return $this->notes
                    ->filter(fn ($n) => $includeInternal || $n->visibility === 'applicant_visible')
                    ->values()
                    ->map(fn ($n) => [
                        'id' => $n->id,
                        'note' => $n->note,
                        'visibility' => $n->visibility,
                        'created_at' => $n->created_at,
                        'author' => $n->author?->name,
                    ]);
            }),
            'commission_agreements' => $this->whenLoaded('commissionAgreements', fn () => $this->commissionAgreements->map(fn ($a) => [
                'id' => $a->id,
                'commission_type' => $a->commission_type,
                'commission_rate' => $a->commission_rate,
                'fixed_fee_cents' => $a->fixed_fee_cents,
                'settlement_frequency' => $a->settlement_frequency,
                'status' => $a->status,
                'effective_from' => $a->effective_from,
                'effective_until' => $a->effective_until,
                'accepted_at' => $a->accepted_at,
                'terms_version' => $a->terms_version,
            ])),
            'updated_at' => $this->updated_at,
            'created_at' => $this->created_at,
        ];
    }
}
