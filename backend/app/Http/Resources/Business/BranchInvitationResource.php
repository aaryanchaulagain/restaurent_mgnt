<?php

namespace App\Http\Resources\Business;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\BranchInvitation */
class BranchInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'email' => $this->email,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'role' => $this->role,
            'status' => $this->status,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'resend_count' => $this->resend_count,
            'last_resent_at' => $this->last_resent_at?->toIso8601String(),
            'invited_by' => $this->whenLoaded('invitedBy', fn () => [
                'name' => $this->invitedBy?->name,
                'email' => $this->invitedBy?->email,
            ]),
            'branch' => $this->whenLoaded('branch', fn () => [
                'public_id' => $this->branch?->public_id,
                'name' => $this->branch?->name,
                'status' => $this->branch?->status,
            ]),
            'business' => $this->whenLoaded('business', fn () => [
                'public_id' => $this->business?->public_id,
                'name' => $this->business?->name,
                'status' => $this->business?->status,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
