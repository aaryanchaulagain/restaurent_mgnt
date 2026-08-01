<?php

namespace App\Support;

final class VendorTypes
{
    public const RESTAURANT = 'restaurant';

    public const BAKERY = 'bakery';

    public const BUTCHERY = 'butchery';

    public const GROCERY = 'grocery';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::RESTAURANT,
            self::BAKERY,
            self::BUTCHERY,
            self::GROCERY,
        ];
    }

    public static function label(string $type): string
    {
        return match ($type) {
            self::BAKERY => 'Bakery',
            self::BUTCHERY => 'Butchery',
            self::GROCERY => 'Grocery',
            default => 'Restaurant',
        };
    }

    public static function portalLabel(string $type): string
    {
        return self::label($type).' Admin';
    }
}
