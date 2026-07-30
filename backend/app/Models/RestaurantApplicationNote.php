<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantApplicationNote extends Model
{
    protected $fillable = [
        'application_id',
        'author_user_id',
        'note',
        'visibility',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(RestaurantApplication::class, 'application_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function isInternal(): bool
    {
        return $this->visibility === 'internal';
    }
}
