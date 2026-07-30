<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutQuote extends Model
{
    protected $fillable = [
        'public_id', 'cart_id', 'customer_id', 'restaurant_id', 'fulfilment_type',
        'address_snapshot', 'pricing_snapshot', 'warnings', 'expires_at',
        'status', 'converted_order_id',
    ];

    protected function casts(): array
    {
        return [
            'address_snapshot' => 'array',
            'pricing_snapshot' => 'array',
            'warnings' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }
}
