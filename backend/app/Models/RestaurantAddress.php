<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantAddress extends Model
{
    protected $fillable = [
        'restaurant_id',
        'application_id',
        'address_type',
        'address_line_1',
        'address_line_2',
        'suburb',
        'state',
        'postcode',
        'country',
        'latitude',
        'longitude',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(RestaurantApplication::class, 'application_id');
    }
}
