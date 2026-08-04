<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'public_id', 'order_number', 'idempotency_key', 'idempotency_scope', 'idempotency_payload_hash', 'restaurant_id', 'customer_id',
        'cart_id', 'checkout_quote_id', 'guest_token_hash', 'status', 'payment_method',
        'payment_status', 'payment_provider', 'payment_reference', 'fulfilment_type', 'currency', 'customer_name_snapshot',
        'customer_email_snapshot', 'customer_phone_snapshot', 'delivery_address_snapshot',
        'pickup_instructions', 'delivery_instructions', 'customer_notes', 'contactless_delivery',
        'subtotal_cents', 'discount_cents', 'tax_cents', 'service_fee_cents', 'delivery_fee_cents',
        'total_cents', 'commission_rate_snapshot', 'commission_amount_cents',
        'restaurant_net_estimate_cents', 'placed_at', 'accepted_at', 'rejected_at',
        'preparing_at', 'ready_at', 'completed_at', 'cancelled_at', 'expires_at',
        'estimated_ready_at', 'accepted_by', 'rejection_reason', 'rejection_explanation',
        'rejection_internal_note', 'cancellation_reason', 'cancellation_actor_type',
    ];

    protected function casts(): array
    {
        return [
            'delivery_address_snapshot' => 'array',
            'contactless_delivery' => 'boolean',
            'commission_rate_snapshot' => 'float',
            'placed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'preparing_at' => 'datetime',
            'ready_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expires_at' => 'datetime',
            'estimated_ready_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Operational restaurant relation (excludes soft-deleted partners).
     * Soft-deleted restaurants must not appear in live partner queues via default eager loads.
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * Historical restaurant resolution including soft-deleted partners.
     * Use for authorized historical/admin reads only — never for public marketplace.
     */
    public function historicalRestaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class, 'restaurant_id')->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function checkoutQuote(): BelongsTo
    {
        return $this->belongsTo(CheckoutQuote::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(OrderAdjustment::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
