<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    protected $fillable = [
        'public_id',
        'payment_id',
        'order_id',
        'attempt_number',
        'idempotency_key',
        'request_payload_hash',
        'status',
        'external_payment_intent_id',
        'client_secret_encrypted',
        'amount_cents',
        'currency',
        'failure_code',
        'failure_message',
        'requires_action',
        'started_at',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'amount_cents' => 'integer',
            'requires_action' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
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
}
