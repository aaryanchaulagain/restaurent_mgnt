<?php

namespace App\Http\Resources\Public;

use App\Models\Restaurant;
use App\Models\RestaurantAddress;
use App\Models\RestaurantMedia;
use App\Services\Media\PublicImageService;
use App\Support\BusinessTypes;
use App\Support\PublicImageUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Restaurant */
class PublicRestaurantResource extends JsonResource
{
    public function __construct(
        $resource,
        private readonly bool $openNow = false,
        private readonly ?PublicImageService $images = null,
        private readonly ?array $todayHours = null,
        private readonly ?string $nextOpening = null,
    ) {
        parent::__construct($resource);
        $this->images ??= app(PublicImageService::class);
    }

    public function toArray(Request $request): array
    {
        $primary = RestaurantAddress::query()
            ->where('restaurant_id', $this->id)
            ->where('is_primary', true)
            ->first();

        $gallery = RestaurantMedia::query()
            ->where('restaurant_id', $this->id)
            ->where('type', 'gallery')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($m) => [
                'thumbnail_url' => PublicImageUrls::PLACEHOLDER,
                'original_url' => PublicImageUrls::PLACEHOLDER,
                'alt_text' => $m->alt_text,
            ]);

        return [
            'public_id' => $this->public_id,
            'slug' => $this->slug,
            'trading_name' => $this->trading_name,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'price_level' => $this->price_level,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'logo' => $this->images->toPublicPayload($this->logo_urls, 'logo'),
            'cover' => $this->images->toPublicPayload($this->cover_urls, 'cover'),
            'gallery' => $gallery,
            'minimum_order_cents' => $this->minimum_order_cents,
            'average_preparation_minutes' => $this->average_preparation_minutes,
            'pickup_enabled' => (bool) $this->pickup_enabled,
            'restaurant_delivery_enabled' => (bool) $this->restaurant_delivery_enabled,
            'third_party_delivery_enabled' => (bool) $this->third_party_delivery_enabled,
            'accepting_orders' => (bool) $this->accepting_orders,
            'is_platform_restaurant' => (bool) $this->is_platform_restaurant,
            'is_featured_partner' => $this->slug === config('suvakamana.platform_restaurant_slug', 'suvakamana-restaurant'),
            'vendor_type' => $this->vendor_type ?: 'restaurant',
            // Presentation-only vertical (normalized); does not rewrite stored vendor_type.
            'business_type' => BusinessTypes::forRestaurant(
                $this->relationLoaded('business')
                    ? ($this->business?->business_type)
                    : $this->business()->value('business_type'),
                $this->vendor_type,
            ),
            'is_open' => $this->openNow,
            'today_hours' => $this->todayHours,
            'next_opening_time' => $this->nextOpening,
            'address_summary' => $primary ? [
                'suburb' => $primary->suburb,
                'state' => $primary->state,
                'postcode' => $primary->postcode,
                'address_line_1' => $primary->address_line_1,
            ] : null,
            'cuisines' => $this->whenLoaded('cuisines', fn () => $this->cuisines->map(fn ($c) => [
                'name' => $c->name,
                'slug' => $c->slug,
                'is_primary' => (bool) $c->pivot->is_primary,
            ])),
        ];
    }
}
