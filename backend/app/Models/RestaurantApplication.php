<?php

namespace App\Models;

use App\Enums\Partner\ApplicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class RestaurantApplication extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'applicant_user_id',
        'restaurant_id',
        'status',
        'legal_business_name',
        'trading_name',
        'business_type',
        'abn',
        'business_registration_number',
        'business_email',
        'business_phone',
        'website_url',
        'description',
        'primary_contact_name',
        'primary_contact_email',
        'primary_contact_phone',
        'cuisine_summary',
        'service_type',
        'expected_monthly_orders',
        'current_delivery_method',
        'location_count',
        'referral_source',
        'assigned_reviewer_id',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'approved_at',
        'rejected_at',
        'rejection_category',
        'rejection_reason',
        'changes_requested_reason',
        'changes_requested_items',
        'terms_version',
        'terms_accepted_at',
        'terms_accepted_ip',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'changes_requested_items' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'location_count' => 'integer',
            'version' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_user_id');
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function assignedReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_reviewer_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(RestaurantAddress::class, 'application_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RestaurantDocument::class, 'application_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(RestaurantApplicationStatusHistory::class, 'application_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(RestaurantApplicationNote::class, 'application_id');
    }

    public function commissionAgreements(): HasMany
    {
        return $this->hasMany(RestaurantCommissionAgreement::class, 'application_id');
    }

    public function activeCommissionAgreement(): HasOne
    {
        return $this->hasOne(RestaurantCommissionAgreement::class, 'application_id')
            ->whereIn('status', ['offered', 'accepted', 'draft'])
            ->latestOfMany();
    }
}
