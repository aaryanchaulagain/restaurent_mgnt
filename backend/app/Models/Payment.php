<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = [
        'public_id',
        'order_id',
        'restaurant_id',
        'customer_id',
        'provider',
        'payment_method_type',
        'status',
        'currency',
        'amount_cents',
        'amount_received_cents',
        'amount_refunded_cents',
        'platform_fee_cents',
        'restaurant_share_cents',
        'processing_fee_cents',
        'external_payment_intent_id',
        'external_charge_id',
        'connected_account_id',
        'transfer_group',
        'provider_created_at',
        'paid_at',
        'failed_at',
        'cancelled_at',
        'last_error_code',
        'last_error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'amount_received_cents' => 'integer',
            'amount_refunded_cents' => 'integer',
            'platform_fee_cents' => 'integer',
            'restaurant_share_cents' => 'integer',
            'processing_fee_cents' => 'integer',
            'metadata' => 'array',
            'provider_created_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(PaymentDispute::class);
    }
}
