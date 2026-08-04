<?php

namespace App\Support;

/**
 * Deterministic straight-line distance (Haversine). Not driving distance or ETA.
 */
final class GeoDistance
{
    /**
     * Approximate distance in kilometres between two WGS84 points.
     */
    public static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Round for customer-facing display (one decimal place).
     */
    public static function roundKm(float $km): float
    {
        return round($km, 1);
    }

    public static function isValidLatitude(mixed $value): bool
    {
        return is_numeric($value) && (float) $value >= -90.0 && (float) $value <= 90.0;
    }

    public static function isValidLongitude(mixed $value): bool
    {
        return is_numeric($value) && (float) $value >= -180.0 && (float) $value <= 180.0;
    }
}
