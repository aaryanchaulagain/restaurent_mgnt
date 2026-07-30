<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CartItem extends Model
{
    protected $fillable = [
        'public_id', 'cart_id', 'menu_item_id', 'menu_item_variant_id',
        'quantity', 'special_instructions', 'unit_price_snapshot_cents', 'estimated_total_cents',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(MenuItemVariant::class, 'menu_item_variant_id');
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(CartItemModifier::class);
    }
}
