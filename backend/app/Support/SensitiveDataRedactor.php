<?php

namespace App\Support;

/**
 * Redact sensitive keys from arrays before logging or audit persistence.
 * Never logs or returns secret values.
 */
final class SensitiveDataRedactor
{
    /**
     * Exact key matches only (short keys that would false-positive via substring).
     *
     * @var list<string>
     */
    public const EXACT_KEYS = [
        'password',
        'password_confirmation',
        'temporary_password',
        'current_password',
        'token',
        'secret',
        'code',
        'cookie',
        'authorization',
        'cvc',
        'cvv',
        'latitude',
        'longitude',
    ];

    /**
     * Substring matches for compound sensitive keys.
     *
     * @var list<string>
     */
    public const SUBSTRING_KEYS = [
        'password',
        'invitation_token',
        'reset_token',
        'secret_encrypted',
        'webhook_secret',
        'stripe_secret',
        'remember_token',
        'recovery_codes',
        'card_number',
        'payment_token',
        'client_secret',
        'api_key',
        'access_token',
        'refresh_token',
        'stripe-signature',
        'stripe_signature',
        'exact_coordinates',
        'browser_coordinates',
        'temporary_password',
    ];

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    public static function scrub(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $out = [];
        foreach ($values as $key => $value) {
            $normalized = strtolower((string) $key);
            if (self::isBlocked($normalized)) {
                $out[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                // Validation error bags are list<string>; only scrub associative children.
                $isList = array_is_list($value);
                $out[$key] = $isList ? $value : self::scrub($value);
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    public static function isBlocked(string $normalizedKey): bool
    {
        if (in_array($normalizedKey, self::EXACT_KEYS, true)) {
            return true;
        }

        foreach (self::SUBSTRING_KEYS as $blocked) {
            if ($normalizedKey !== $blocked && str_contains($normalizedKey, $blocked)) {
                return true;
            }
            if ($normalizedKey === $blocked) {
                return true;
            }
        }

        return false;
    }
}
