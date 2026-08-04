<?php

namespace App\Support;

/**
 * Detect Stripe test vs live key mode without printing key values.
 */
final class StripeKeyMode
{
    public static function modeOf(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        if (str_starts_with($key, 'sk_live_') || str_starts_with($key, 'pk_live_') || str_starts_with($key, 'rk_live_')) {
            return 'live';
        }

        if (str_starts_with($key, 'sk_test_') || str_starts_with($key, 'pk_test_') || str_starts_with($key, 'rk_test_')) {
            return 'test';
        }

        // Unknown shape — do not guess; caller treats as unspecified.
        return 'unknown';
    }

    /**
     * @return array{consistent: bool, secret_mode: ?string, publishable_mode: ?string, message: string}
     */
    public static function compare(?string $secret, ?string $publishable): array
    {
        $secretMode = self::modeOf($secret);
        $pubMode = self::modeOf($publishable);

        if ($secretMode === null && $pubMode === null) {
            return [
                'consistent' => true,
                'secret_mode' => null,
                'publishable_mode' => null,
                'message' => 'Stripe keys not configured.',
            ];
        }

        if ($secretMode === 'live' || $pubMode === 'live') {
            $env = (string) config('app.env');
            if (in_array($env, ['staging', 'local', 'testing'], true)) {
                return [
                    'consistent' => false,
                    'secret_mode' => $secretMode,
                    'publishable_mode' => $pubMode,
                    'message' => 'Live Stripe keys must not be used in staging/local.',
                ];
            }
        }

        if ($secretMode !== null && $pubMode !== null && $secretMode !== 'unknown' && $pubMode !== 'unknown' && $secretMode !== $pubMode) {
            return [
                'consistent' => false,
                'secret_mode' => $secretMode,
                'publishable_mode' => $pubMode,
                'message' => 'Mixed Stripe test/live keys are not allowed.',
            ];
        }

        return [
            'consistent' => true,
            'secret_mode' => $secretMode,
            'publishable_mode' => $pubMode,
            'message' => 'Stripe key modes are consistent.',
        ];
    }
}
