<?php

namespace App\Support;

/**
 * Safe public release identifier. Never includes paths or credentials.
 */
final class ReleaseIdentifier
{
    public static function version(): string
    {
        return (string) config('app.version', 'v1');
    }

    /**
     * Short Git SHA or empty string — safe for health responses.
     */
    public static function shortSha(): string
    {
        $sha = (string) (config('app.release_sha') ?: '');
        $sha = preg_replace('/[^a-fA-F0-9]/', '', $sha) ?? '';

        if ($sha === '') {
            return '';
        }

        return substr($sha, 0, 7);
    }

    /**
     * @return array{version: string, release?: string}
     */
    public static function forHealth(): array
    {
        $payload = ['version' => self::version()];
        $short = self::shortSha();
        if ($short !== '') {
            $payload['release'] = $short;
        }

        return $payload;
    }
}
