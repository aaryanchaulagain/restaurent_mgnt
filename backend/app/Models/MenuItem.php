<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MenuItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id', 'restaurant_id', 'menu_id', 'menu_category_id', 'name', 'slug',
        'short_description', 'description', 'image_path', 'image_urls', 'base_price_cents',
        'compare_at_price_cents', 'cost_price_cents', 'tax_category', 'preparation_minutes',
        'is_active', 'is_available', 'is_featured', 'is_vegetarian', 'is_vegan',
        'is_gluten_free', 'is_halal', 'spice_level', 'calories', 'sort_order',
        'available_from', 'available_until',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_available' => 'boolean',
            'is_featured' => 'boolean',
            'is_vegetarian' => 'boolean',
            'is_vegan' => 'boolean',
            'is_gluten_free' => 'boolean',
            'is_halal' => 'boolean',
            'available_from' => 'datetime',
            'available_until' => 'datetime',
            'image_urls' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(MenuItemVariant::class);
    }

    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'menu_item_modifier_groups')
            ->withPivot('sort_order');
    }

    public function allergens(): BelongsToMany
    {
        return $this->belongsToMany(Allergen::class, 'menu_item_allergens')
            ->withPivot('presence_type');
    }
}
