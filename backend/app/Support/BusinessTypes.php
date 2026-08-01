<?php

namespace App\Support;

final class BusinessTypes
{
    public const RESTAURANT = 'restaurant';

    public const BAKERY = 'bakery';

    public const GROCERY = 'grocery';

    public const BUTCHER = 'butcher';

    public const PHARMACY = 'pharmacy';

    public const OTHER = 'other';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::RESTAURANT,
            self::BAKERY,
            self::GROCERY,
            self::BUTCHER,
            self::PHARMACY,
            self::OTHER,
        ];
    }

    public static function fromVendorType(?string $vendorType): string
    {
        return match ($vendorType) {
            'bakery' => self::BAKERY,
            'grocery' => self::GROCERY,
            'butchery', 'butcher' => self::BUTCHER,
            'pharmacy' => self::PHARMACY,
            'other' => self::OTHER,
            default => self::RESTAURANT,
        };
    }

    public static function label(string $type): string
    {
        return match ($type) {
            self::BAKERY => 'Bakery',
            self::GROCERY => 'Grocery',
            self::BUTCHER => 'Butchery',
            self::PHARMACY => 'Pharmacy',
            self::OTHER => 'Other',
            default => 'Restaurant',
        };
    }
}
