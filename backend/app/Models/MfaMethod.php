<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MfaMethod extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'secret_encrypted',
        'is_confirmed',
        'is_primary',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'secret_encrypted' => 'encrypted',
            'is_confirmed' => 'boolean',
            'is_primary' => 'boolean',
            'confirmed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
