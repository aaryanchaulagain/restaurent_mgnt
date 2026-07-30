<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantServiceArea extends Model
{
    protected $fillable = [
        'restaurant_id', 'type', 'postcode', 'radius_km',
        'minimum_order_cents', 'delivery_fee_cents', 'free_delivery_threshold_cents',
        'estimated_minutes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'radius_km' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
