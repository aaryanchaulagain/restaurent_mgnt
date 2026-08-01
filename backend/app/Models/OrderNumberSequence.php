<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderNumberSequence extends Model
{
    protected $fillable = [
        'date',
        'last_sequence',
    ];

    protected function casts(): array
    {
        return [
            // Keep as plain string (Y-m-d). Do not cast to date/datetime — that
            // serializes as Y-m-d H:i:s on SQLite and breaks unique lookups.
            'last_sequence' => 'integer',
        ];
    }
}
