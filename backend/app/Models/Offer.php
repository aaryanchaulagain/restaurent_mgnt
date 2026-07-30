<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Offer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id', 'restaurant_id', 'name', 'description', 'offer_type', 'value',
        'minimum_order_cents', 'maximum_discount_cents', 'starts_at', 'ends_at',
        'is_active', 'usage_limit', 'usage_limit_per_customer',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'value' => 'decimal:2',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(OfferTarget::class);
    }
}
