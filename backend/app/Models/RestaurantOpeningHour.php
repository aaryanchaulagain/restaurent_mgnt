<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantOpeningHour extends Model
{
    protected $table = 'restaurant_opening_hours';

    protected $fillable = [
        'restaurant_id', 'day_of_week', 'opens_at', 'closes_at', 'is_closed', 'service_type',
    ];

    protected function casts(): array
    {
        return ['is_closed' => 'boolean'];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
