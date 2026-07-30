<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentWebhookEvent extends Model
{
    protected $fillable = [
        'public_id',
        'provider',
        'external_event_id',
        'event_type',
        'payload_hash',
        'livemode',
        'api_version',
        'processing_status',
        'processing_attempts',
        'received_at',
        'processed_at',
        'failed_at',
        'last_error',
        'related_payment_id',
        'related_order_id',
        'sanitized_payload',
    ];

    protected function casts(): array
    {
        return [
            'livemode' => 'boolean',
            'processing_attempts' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
            'sanitized_payload' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function relatedPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'related_payment_id');
    }

    public function relatedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'related_order_id');
    }
}
