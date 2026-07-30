<?php

namespace App\Http\Resources\Restaurant;

use App\Models\Restaurant;
use App\Models\RestaurantAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Restaurant */
class RestaurantProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $primaryAddress = RestaurantAddress::query()
            ->where('restaurant_id', $this->id)
            ->where('is_primary', true)
            ->first();

        return [
            'public_id' => $this->public_id,
            'slug' => $this->slug,
            'legal_business_name' => $this->legal_business_name,
            'trading_name' => $this->trading_name,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'business_email' => $this->business_email,
            'business_phone' => $this->business_phone,
            'website_url' => $this->website_url,
            'status' => $this->status?->value ?? $this->status,
            'verification_status' => $this->verification_status,
            'primary_cuisine_id' => $this->primary_cuisine_id,
            'cuisine_ids' => $this->whenLoaded('cuisines', fn () => $this->cuisines->pluck('id')),
            'price_level' => $this->price_level,
            'logo_path' => $this->logo_path,
            'cover_image_path' => $this->cover_image_path,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'minimum_order_cents' => $this->minimum_order_cents,
            'average_preparation_minutes' => $this->average_preparation_minutes,
            'pickup_enabled' => (bool) $this->pickup_enabled,
            'restaurant_delivery_enabled' => (bool) $this->restaurant_delivery_enabled,
            'third_party_delivery_enabled' => (bool) $this->third_party_delivery_enabled,
            'dine_in_enabled' => (bool) $this->dine_in_enabled,
            'accepting_orders' => (bool) $this->accepting_orders,
            'temporarily_closed_reason' => $this->temporarily_closed_reason,
            'temporarily_closed_until' => $this->temporarily_closed_until?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'primary_address' => $primaryAddress ? [
                'address_line_1' => $primaryAddress->address_line_1,
                'address_line_2' => $primaryAddress->address_line_2,
                'suburb' => $primaryAddress->suburb,
                'state' => $primaryAddress->state,
                'postcode' => $primaryAddress->postcode,
                'country' => $primaryAddress->country,
                'latitude' => $primaryAddress->latitude,
                'longitude' => $primaryAddress->longitude,
            ] : null,
        ];
    }
}
