<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantApplicationStatusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'restaurant_application_status_history';

    protected $fillable = [
        'application_id',
        'old_status',
        'new_status',
        'changed_by',
        'reason',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(RestaurantApplication::class, 'application_id');
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
