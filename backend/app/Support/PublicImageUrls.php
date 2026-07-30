<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

final class PublicImageUrls
{
    /** Remote fallback — local /images/marketplace assets are not shipped in public/. */
    public const PLACEHOLDER = 'https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?auto=format&fit=crop&w=1200&q=80';

    public const ITEM_PLACEHOLDER = 'https://images.unsplash.com/photo-1546833999-b9f581a1996d?auto=format&fit=crop&w=800&q=80';

    /**
     * @param  array<string, string>|null  $urls
     * @return array{thumbnail_url: string, card_url: string, large_url: string, original_url: string}
     */
    public static function normalize(?array $urls, string $kind = 'cover'): array
    {
        if (! $urls) {
            return self::placeholder($kind);
        }

        return [
            'thumbnail_url' => $urls['thumbnail'] ?? $urls['thumbnail_url'] ?? self::PLACEHOLDER,
            'card_url' => $urls['card'] ?? $urls['card_url'] ?? ($urls['original'] ?? self::PLACEHOLDER),
            'large_url' => $urls['large'] ?? $urls['large_url'] ?? ($urls['original'] ?? self::PLACEHOLDER),
            'original_url' => $urls['original'] ?? $urls['original_url'] ?? self::PLACEHOLDER,
        ];
    }

    /**
     * @return array{thumbnail_url: string, card_url: string, large_url: string, original_url: string}
     */
    public static function placeholder(string $kind = 'cover'): array
    {
        $url = $kind === 'item' || $kind === 'food' ? self::ITEM_PLACEHOLDER : self::PLACEHOLDER;

        return [
            'thumbnail_url' => $url,
            'card_url' => $url,
            'large_url' => $url,
            'original_url' => $url,
        ];
    }

    public static function publicUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $relative = Storage::disk('public')->url($path);

        return rtrim((string) config('app.url'), '/').$relative;
    }
}
