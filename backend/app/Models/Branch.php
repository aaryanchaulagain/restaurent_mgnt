<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Support\BranchStatuses;

class Branch extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'business_id',
        'restaurant_id',
        'name',
        'code',
        'email',
        'phone',
        'address_line',
        'city',
        'state',
        'postcode',
        'country',
        'latitude',
        'longitude',
        'delivery_radius_km',
        'minimum_order_amount_cents',
        'accepting_orders',
        'is_default',
        'status',
        'timezone',
        'sort_order',
        'suspended_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'delivery_radius_km' => 'float',
            'accepting_orders' => 'boolean',
            'is_default' => 'boolean',
            'suspended_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function branchUsers(): HasMany
    {
        return $this->hasMany(BranchUser::class);
    }

    public function isActive(): bool
    {
        return $this->status === BranchStatuses::ACTIVE && $this->suspended_at === null;
    }

    public function isEligibleForOrders(): bool
    {
        return $this->isActive() && $this->accepting_orders;
    }

    public function allowsConfiguration(): bool
    {
        return BranchStatuses::allowsConfiguration((string) $this->status);
    }
}
