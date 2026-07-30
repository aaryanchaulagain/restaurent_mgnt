<?php

namespace App\Models;

use App\Enums\Partner\RestaurantStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Restaurant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'slug',
        'legal_business_name',
        'trading_name',
        'short_description',
        'description',
        'business_email',
        'business_phone',
        'website_url',
        'abn',
        'business_registration_number',
        'status',
        'verification_status',
        'primary_cuisine_id',
        'price_level',
        'logo_path',
        'cover_image_path',
        'logo_urls',
        'cover_urls',
        'timezone',
        'currency',
        'minimum_order_cents',
        'average_preparation_minutes',
        'pickup_enabled',
        'restaurant_delivery_enabled',
        'third_party_delivery_enabled',
        'dine_in_enabled',
        'accepting_orders',
        'temporarily_closed_reason',
        'temporarily_closed_until',
        'published_at',
        'approved_at',
        'approved_by',
        'suspended_at',
        'suspension_reason',
        'ownership_type',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
            'published_at' => 'datetime',
            'temporarily_closed_until' => 'datetime',
            'status' => RestaurantStatus::class,
            'pickup_enabled' => 'boolean',
            'restaurant_delivery_enabled' => 'boolean',
            'third_party_delivery_enabled' => 'boolean',
            'dine_in_enabled' => 'boolean',
            'accepting_orders' => 'boolean',
            'logo_urls' => 'array',
            'cover_urls' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function restaurantUsers(): HasMany
    {
        return $this->hasMany(RestaurantUser::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(RestaurantApplication::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(RestaurantAddress::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RestaurantDocument::class);
    }

    public function commissionAgreements(): HasMany
    {
        return $this->hasMany(RestaurantCommissionAgreement::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function primaryCuisine(): BelongsTo
    {
        return $this->belongsTo(Cuisine::class, 'primary_cuisine_id');
    }

    public function cuisines(): BelongsToMany
    {
        return $this->belongsToMany(Cuisine::class, 'restaurant_cuisines')
            ->withPivot('is_primary');
    }

    public function serviceAreas(): HasMany
    {
        return $this->hasMany(RestaurantServiceArea::class);
    }

    public function openingHours(): HasMany
    {
        return $this->hasMany(RestaurantOpeningHour::class);
    }

    public function specialHours(): HasMany
    {
        return $this->hasMany(RestaurantSpecialHour::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(RestaurantMedia::class);
    }

    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class);
    }

    public function menuCategories(): HasMany
    {
        return $this->hasMany(MenuCategory::class);
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function paymentAccount(): HasOne
    {
        return $this->hasOne(RestaurantPaymentAccount::class);
    }

    public function isPubliclyListed(): bool
    {
        if ($this->suspended_at || $this->status === RestaurantStatus::Disabled) {
            return false;
        }

        return $this->status === RestaurantStatus::Active && $this->published_at !== null;
    }

    public function isFirstParty(): bool
    {
        return $this->ownership_type === 'first_party';
    }

    public function isPubliclyVisible(): bool
    {
        $status = $this->status instanceof RestaurantStatus
            ? $this->status
            : RestaurantStatus::tryFrom((string) $this->status);

        return $status?->isPubliclyVisible() ?? false;
    }
}
