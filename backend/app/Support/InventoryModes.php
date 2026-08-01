<?php

namespace App\Support;

/**
 * Inventory capability modes by business vertical.
 * Authorization must never rely on this alone.
 */
final class InventoryModes
{
    public const NONE = 'none';

    /** Manual sold-out / available toggles only — no quantity tracking. */
    public const BOOLEAN = 'boolean';

    /** Quantity on hand with low-stock thresholds and availability sync. */
    public const COUNTED = 'counted';

    public static function forBusinessType(?string $type): string
    {
        return match (BusinessTypes::normalize($type)) {
            BusinessTypes::GROCERY,
            BusinessTypes::BUTCHER,
            BusinessTypes::BAKERY,
            BusinessTypes::PHARMACY => self::COUNTED,
            BusinessTypes::RESTAURANT,
            BusinessTypes::OTHER => self::BOOLEAN,
            default => self::BOOLEAN,
        };
    }

    public static function tracksQuantity(?string $type): bool
    {
        return self::forBusinessType($type) === self::COUNTED;
    }

    public static function supportsInventoryUi(?string $type): bool
    {
        return self::forBusinessType($type) !== self::NONE;
    }
}
