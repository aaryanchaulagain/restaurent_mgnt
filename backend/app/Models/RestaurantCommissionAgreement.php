<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantCommissionAgreement extends Model
{
    protected $fillable = [
        'restaurant_id',
        'application_id',
        'commission_type',
        'commission_rate',
        'fixed_fee_cents',
        'processing_fee_responsibility',
        'delivery_fee_responsibility',
        'discount_calculation_method',
        'settlement_frequency',
        'effective_from',
        'effective_until',
        'status',
        'created_by',
        'accepted_by',
        'accepted_at',
        'terms_version',
    ];

    protected function casts(): array
    {
        return [
            'commission_rate' => 'decimal:2',
            'fixed_fee_cents' => 'integer',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'accepted_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(RestaurantApplication::class, 'application_id');
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function acceptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }
}
