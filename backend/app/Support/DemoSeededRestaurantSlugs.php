<?php

namespace App\Support;

/**
 * Exact restaurant slugs created by demo seeders (Phase4/5A/Sold).
 * Used for archival and integrity classification — never fuzzy-matched.
 */
final class DemoSeededRestaurantSlugs
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            'himalayan-kitchen',
            'harbour-spice-pending',
            'golden-wok',
            'night-owl',
            'temp-closed',
            'pending-setup',
            'suspended-grill',
            'disabled-diner',
            'no-menu',
            'pickup-only',
            'postcode-delivery',
            'sold-out-items',
            'variant-heavy',
            'required-mods',
            'optional-mods',
            'allergen-labelled',
            'future-offer',
            'expired-offer',
            'sold-panels-kitchen',
        ];
    }

    public static function isDemoSlug(?string $slug): bool
    {
        return is_string($slug) && in_array($slug, self::all(), true);
    }

    /** Deterministic Phase6PaymentSeeder order public ID pattern. */
    public static function isPhase6PaymentFixtureUuid(?string $publicId): bool
    {
        return is_string($publicId)
            && (bool) preg_match('/^550e8400-e29b-41d4-a716-[0-9a-f]{12}$/i', $publicId);
    }

    public static function isSeedOrderNumber(?string $orderNumber): bool
    {
        return is_string($orderNumber)
            && (str_starts_with($orderNumber, 'PAY-SEED-')
                || str_starts_with($orderNumber, 'SVK-SEED-'));
    }
}
