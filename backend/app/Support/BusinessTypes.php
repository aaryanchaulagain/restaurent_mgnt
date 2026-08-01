<?php

namespace App\Support;

/**
 * Authoritative marketplace vertical types (businesses.business_type).
 *
 * Presentation / catalogue behaviour should normalize through this class.
 * Do not confuse with restaurant_applications.business_type (legal entity).
 */
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

    /**
     * Normalize aliases and unknown/missing values for catalogue presentation.
     * Does not rewrite stored rows — call sites decide what to persist.
     */
    public static function normalize(?string $type): string
    {
        if ($type === null) {
            return self::OTHER;
        }

        $key = strtolower(trim($type));
        if ($key === '') {
            return self::OTHER;
        }

        return match ($key) {
            self::RESTAURANT => self::RESTAURANT,
            self::BAKERY => self::BAKERY,
            self::GROCERY, 'grocery_store' => self::GROCERY,
            self::BUTCHER, 'butchery', 'meat_shop' => self::BUTCHER,
            self::PHARMACY => self::PHARMACY,
            self::OTHER => self::OTHER,
            default => self::OTHER,
        };
    }

    /**
     * Map legacy restaurants.vendor_type into a business vertical.
     * Kept for provisioning / migration compatibility (unknown → restaurant).
     */
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

    /**
     * Resolve the vertical for an operational restaurant without rewriting data.
     * Prefers businesses.business_type; falls back to vendor_type aliases.
     */
    public static function forRestaurant(?string $businessType, ?string $vendorType): string
    {
        if ($businessType !== null && trim($businessType) !== '') {
            return self::normalize($businessType);
        }

        if ($vendorType !== null && trim($vendorType) !== '') {
            return self::normalize($vendorType);
        }

        return self::OTHER;
    }

    public static function isRestaurant(?string $type): bool
    {
        return self::normalize($type) === self::RESTAURANT;
    }

    public static function isBakery(?string $type): bool
    {
        return self::normalize($type) === self::BAKERY;
    }

    public static function isGrocery(?string $type): bool
    {
        return self::normalize($type) === self::GROCERY;
    }

    public static function isButcher(?string $type): bool
    {
        return self::normalize($type) === self::BUTCHER;
    }

    public static function isPharmacy(?string $type): bool
    {
        return self::normalize($type) === self::PHARMACY;
    }

    public static function label(string $type): string
    {
        return match (self::normalize($type)) {
            self::BAKERY => 'Bakery',
            self::GROCERY => 'Grocery',
            self::BUTCHER => 'Butchery',
            self::PHARMACY => 'Pharmacy',
            self::OTHER => 'Other',
            default => 'Restaurant',
        };
    }

    public static function portalLabel(?string $type): string
    {
        return self::label(self::normalize($type)).' Admin';
    }

    /**
     * Presentation / capability hints for operator UIs.
     * Authorization must never rely on this map alone.
     *
     * @return array{
     *   type: string,
     *   label: string,
     *   portal_label: string,
     *   catalogue_label: string,
     *   category_label: string,
     *   product_label: string,
     *   product_plural_label: string,
     *   add_product_label: string,
     *   supports_variants: bool,
     *   supports_modifiers: bool,
     *   supports_dietary: bool,
     *   supports_preparation_time: bool,
     *   supports_cuisine: bool,
     *   inventory_mode: string,
     *   supports_inventory: bool,
     *   default_categories: list<string>
     * }
     */
    public static function catalogueConfig(?string $type): array
    {
        $normalized = self::normalize($type);
        $inventoryMode = InventoryModes::forBusinessType($normalized);

        $base = match ($normalized) {
            self::BAKERY => [
                'type' => self::BAKERY,
                'label' => 'Bakery',
                'portal_label' => 'Bakery Admin',
                'catalogue_label' => 'Products',
                'category_label' => 'Category',
                'product_label' => 'Product',
                'product_plural_label' => 'Products',
                'add_product_label' => 'Add product',
                'supports_variants' => true,
                'supports_modifiers' => true,
                'supports_dietary' => true,
                'supports_preparation_time' => true,
                'supports_cuisine' => false,
                'default_categories' => ['Breads', 'Pastries', 'Cakes', 'Savouries'],
            ],
            self::GROCERY => [
                'type' => self::GROCERY,
                'label' => 'Grocery',
                'portal_label' => 'Grocery Admin',
                'catalogue_label' => 'Products',
                'category_label' => 'Category',
                'product_label' => 'Product',
                'product_plural_label' => 'Products',
                'add_product_label' => 'Add product',
                'supports_variants' => true,
                'supports_modifiers' => false,
                'supports_dietary' => false,
                'supports_preparation_time' => false,
                'supports_cuisine' => false,
                'default_categories' => ['Fresh', 'Pantry', 'Dairy', 'Household'],
            ],
            self::BUTCHER => [
                'type' => self::BUTCHER,
                'label' => 'Butchery',
                'portal_label' => 'Butchery Admin',
                'catalogue_label' => 'Products',
                'category_label' => 'Category',
                'product_label' => 'Cut',
                'product_plural_label' => 'Cuts',
                'add_product_label' => 'Add cut',
                'supports_variants' => true,
                'supports_modifiers' => true,
                'supports_dietary' => false,
                'supports_preparation_time' => false,
                'supports_cuisine' => false,
                'default_categories' => ['Beef', 'Chicken', 'Lamb', 'Pork'],
            ],
            self::PHARMACY => [
                'type' => self::PHARMACY,
                'label' => 'Pharmacy',
                'portal_label' => 'Pharmacy Admin',
                'catalogue_label' => 'Products',
                'category_label' => 'Category',
                'product_label' => 'Product',
                'product_plural_label' => 'Products',
                'add_product_label' => 'Add product',
                'supports_variants' => true,
                'supports_modifiers' => false,
                'supports_dietary' => false,
                'supports_preparation_time' => false,
                'supports_cuisine' => false,
                'default_categories' => ['OTC', 'Personal care', 'Wellness'],
            ],
            self::OTHER => [
                'type' => self::OTHER,
                'label' => 'Business',
                'portal_label' => 'Business Admin',
                'catalogue_label' => 'Products',
                'category_label' => 'Category',
                'product_label' => 'Product',
                'product_plural_label' => 'Products',
                'add_product_label' => 'Add product',
                'supports_variants' => true,
                'supports_modifiers' => true,
                'supports_dietary' => false,
                'supports_preparation_time' => false,
                'supports_cuisine' => false,
                'default_categories' => ['General'],
            ],
            default => [
                'type' => self::RESTAURANT,
                'label' => 'Restaurant',
                'portal_label' => 'Restaurant Admin',
                'catalogue_label' => 'Menu',
                'category_label' => 'Menu category',
                'product_label' => 'Menu item',
                'product_plural_label' => 'Menu items',
                'add_product_label' => 'Add menu item',
                'supports_variants' => true,
                'supports_modifiers' => true,
                'supports_dietary' => true,
                'supports_preparation_time' => true,
                'supports_cuisine' => true,
                'default_categories' => ['Starters', 'Mains', 'Drinks', 'Desserts'],
            ],
        };

        $base['inventory_mode'] = $inventoryMode;
        $base['supports_inventory'] = InventoryModes::supportsInventoryUi($normalized);

        return $base;
    }
}
