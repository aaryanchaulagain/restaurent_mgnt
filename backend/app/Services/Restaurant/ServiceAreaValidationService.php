<?php

namespace App\Services\Restaurant;

use App\Models\Restaurant;
use App\Models\RestaurantServiceArea;
use Illuminate\Support\Facades\DB;

class ServiceAreaValidationService
{
    /**
     * @return array{code: string, supported: bool, message?: string}
     */
    public function validateDeliveryAddress(Restaurant $restaurant, ?string $postcode, ?float $lat, ?float $lng): array
    {
        if (! $restaurant->restaurant_delivery_enabled) {
            return ['code' => 'DELIVERY_DISABLED', 'supported' => false, 'message' => 'Delivery is not enabled.'];
        }

        $areas = RestaurantServiceArea::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->get();

        if ($areas->isEmpty()) {
            return ['code' => 'SERVICE_AREA_UNSUPPORTED', 'supported' => false];
        }

        foreach ($areas as $area) {
            if ($area->type === 'postcode' && $postcode && $area->postcode === $postcode) {
                return ['code' => 'SERVICE_AREA_SUPPORTED', 'supported' => true];
            }
            if ($area->type === 'radius' && $lat && $lng && $area->radius_km) {
                $primary = $restaurant->addresses()->where('is_primary', true)->first();
                if ($primary?->latitude && $primary?->longitude) {
                    $km = $this->haversineKm((float) $primary->latitude, (float) $primary->longitude, $lat, $lng);
                    if ($km <= (float) $area->radius_km) {
                        return ['code' => 'SERVICE_AREA_SUPPORTED', 'supported' => true];
                    }
                }

                return ['code' => 'ADDRESS_COORDINATES_REQUIRED', 'supported' => false];
            }
        }

        return ['code' => 'SERVICE_AREA_UNSUPPORTED', 'supported' => false];
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
