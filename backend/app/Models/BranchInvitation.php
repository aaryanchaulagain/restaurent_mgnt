<?php

namespace App\Models;

use App\Support\BranchInvitationStatuses;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchInvitation extends Model
{
    protected $fillable = [
        'public_id',
        'business_id',
        'branch_id',
        'invited_by_user_id',
        'email',
        'full_name',
        'phone',
        'role',
        'token_hash',
        'status',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'accepted_by_user_id',
        'resend_count',
        'last_resent_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_resent_at' => 'datetime',
            'resend_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === BranchInvitationStatuses::PENDING;
    }

    public function isExpired(): bool
    {
        if ($this->status === BranchInvitationStatuses::EXPIRED) {
            return true;
        }

        return $this->isPending() && $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->status === BranchInvitationStatuses::REVOKED;
    }

    public function isAccepted(): bool
    {
        return $this->status === BranchInvitationStatuses::ACCEPTED;
    }
}
