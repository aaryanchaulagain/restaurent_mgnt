<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantPaymentAccount extends Model
{
    protected $fillable = [
        'restaurant_id',
        'provider',
        'external_account_id',
        'account_type',
        'onboarding_status',
        'charges_enabled',
        'payouts_enabled',
        'details_submitted',
        'online_payments_enabled',
        'requirements_currently_due',
        'requirements_eventually_due',
        'disabled_reason',
        'country',
        'default_currency',
        'last_synced_at',
        'onboarding_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'charges_enabled' => 'boolean',
            'payouts_enabled' => 'boolean',
            'details_submitted' => 'boolean',
            'online_payments_enabled' => 'boolean',
            'requirements_currently_due' => 'array',
            'requirements_eventually_due' => 'array',
            'last_synced_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
