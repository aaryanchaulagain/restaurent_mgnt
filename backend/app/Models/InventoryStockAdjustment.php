<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryStockAdjustment extends Model
{
    protected $fillable = [
        'public_id',
        'restaurant_id',
        'menu_item_inventory_id',
        'user_id',
        'delta',
        'quantity_before',
        'quantity_after',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'delta' => 'integer',
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
        ];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(MenuItemInventory::class, 'menu_item_inventory_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
