<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    protected $fillable = [
        'public_id',
        'payment_id',
        'order_id',
        'restaurant_id',
        'requested_by_user_id',
        'approved_by_user_id',
        'provider',
        'external_refund_id',
        'status',
        'amount_cents',
        'currency',
        'reason_category',
        'customer_reason',
        'internal_note',
        'refund_application_fee',
        'reverse_transfer',
        'idempotency_key',
        'provider_failure_code',
        'provider_failure_message',
        'requested_at',
        'completed_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'refund_application_fee' => 'boolean',
            'reverse_transfer' => 'boolean',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
