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
            'date' => 'date',
        ];
    }
}
