<?php

namespace App\Http\Resources\Public;

use App\Enums\Partner\RestaurantStatus;
use App\Models\Branch;
use App\Support\BranchStatuses;
use App\Support\BusinessTypes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Branch */
class PublicBranchResource extends JsonResource
{
    /**
     * @param  array{
     *   is_open_now?: bool,
     *   next_opening_time?: string|null,
     *   today_hours?: array|null
     * }  $ops
     */
    public function __construct(
        $resource,
        private readonly array $ops = [],
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var Branch $branch */
        $branch = $this->resource;
        $restaurant = $branch->restaurant;

        $restaurantStatus = $restaurant?->status instanceof RestaurantStatus
            ? $restaurant->status->value
            : (string) ($restaurant?->status ?? '');

        $temporarilyClosed = $branch->status === BranchStatuses::PAUSED
            || $restaurantStatus === RestaurantStatus::TemporarilyClosed->value;

        $acceptingOrders = (bool) ($branch->accepting_orders && $restaurant?->accepting_orders)
            && $branch->status === BranchStatuses::ACTIVE
            && $restaurantStatus === RestaurantStatus::Active->value
            && ! $temporarilyClosed;

        return [
            'public_id' => $branch->public_id,
            'name' => $branch->name,
            'status' => $branch->status,
            'status_label' => BranchStatuses::label((string) $branch->status),
            'is_default' => (bool) $branch->is_default,
            'is_temporarily_closed' => $temporarilyClosed,
            'accepting_orders' => $acceptingOrders,
            'is_open_now' => (bool) ($this->ops['is_open_now'] ?? false) && $acceptingOrders,
            'next_opening_time' => $this->ops['next_opening_time'] ?? null,
            'today_hours' => $this->ops['today_hours'] ?? null,
            'timezone' => $branch->timezone ?: $restaurant?->timezone,
            'address' => [
                'address_line' => $branch->address_line,
                'city' => $branch->city,
                'state' => $branch->state,
                'postcode' => $branch->postcode,
                'country' => $branch->country,
            ],
            'capabilities' => [
                'pickup_enabled' => (bool) ($restaurant?->pickup_enabled ?? false),
                'delivery_enabled' => (bool) ($restaurant?->restaurant_delivery_enabled ?? false),
                'third_party_delivery_enabled' => (bool) ($restaurant?->third_party_delivery_enabled ?? false),
            ],
            'minimum_order_amount_cents' => $branch->minimum_order_amount_cents
                ?? $restaurant?->minimum_order_amount_cents,
            'restaurant' => $restaurant ? [
                'public_id' => $restaurant->public_id,
                'slug' => $restaurant->slug,
                'trading_name' => $restaurant->trading_name,
                'status' => $restaurantStatus,
                'accepting_orders' => (bool) $restaurant->accepting_orders,
                'business_type' => BusinessTypes::forRestaurant(
                    $branch->business?->business_type ?? $restaurant->business?->business_type,
                    $restaurant->vendor_type,
                ),
            ] : null,
        ];
    }
}
