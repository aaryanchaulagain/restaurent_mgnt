<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuItemInventory extends Model
{
    protected $fillable = [
        'public_id',
        'restaurant_id',
        'menu_item_id',
        'menu_item_variant_id',
        'variant_scope',
        'track_stock',
        'quantity_on_hand',
        'low_stock_threshold',
        'force_unavailable',
    ];

    protected function casts(): array
    {
        return [
            'track_stock' => 'boolean',
            'force_unavailable' => 'boolean',
            'quantity_on_hand' => 'integer',
            'low_stock_threshold' => 'integer',
            'variant_scope' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function isLowStock(?int $availableOverride = null): bool
    {
        if (! $this->track_stock || $this->low_stock_threshold === null) {
            return false;
        }

        $available = $availableOverride ?? $this->quantity_on_hand;

        return $available <= $this->low_stock_threshold;
    }

    public function isInStock(?int $availableOverride = null): bool
    {
        if (! $this->track_stock) {
            return true;
        }

        $available = $availableOverride ?? $this->quantity_on_hand;

        return $available > 0;
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(MenuItemVariant::class, 'menu_item_variant_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(InventoryStockAdjustment::class);
    }
}
