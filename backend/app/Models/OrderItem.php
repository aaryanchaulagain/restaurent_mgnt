<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected $fillable = [
        'public_id', 'order_id', 'menu_item_id', 'menu_item_variant_id',
        'item_name_snapshot', 'item_description_snapshot', 'item_image_snapshot',
        'variant_name_snapshot', 'sku_snapshot', 'unit_price_cents', 'quantity',
        'line_subtotal_cents', 'discount_cents', 'line_total_cents',
        'preparation_minutes_snapshot', 'dietary_snapshot', 'allergen_snapshot',
        'customer_instructions',
    ];

    protected function casts(): array
    {
        return [
            'item_image_snapshot' => 'array',
            'dietary_snapshot' => 'array',
            'allergen_snapshot' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class);
    }
}
