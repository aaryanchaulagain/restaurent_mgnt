<?php

return [
    'driver' => env('PAYMENT_DRIVER', 'stripe'),

    'currency' => env('STRIPE_CURRENCY', 'AUD'),
    'platform_country' => env('STRIPE_PLATFORM_COUNTRY', 'AU'),
    'connect_charge_strategy' => env('STRIPE_CONNECT_CHARGE_STRATEGY', 'destination_charge'),

    'pending_expiry_minutes' => (int) env('PAYMENT_PENDING_EXPIRY_MINUTES', 30),
    'max_retry_attempts' => (int) env('PAYMENT_MAX_RETRY_ATTEMPTS', 5),
    'webhook_max_retries' => (int) env('PAYMENT_WEBHOOK_MAX_RETRIES', 10),

    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Pin Stripe API version used by the PHP SDK client.
        'api_version' => env('STRIPE_API_VERSION', '2024-11-20.acacia'),
        'onboarding_return_url' => env('STRIPE_ONBOARDING_RETURN_URL', 'http://localhost:3000/restaurant/settings/payments/return'),
        'onboarding_refresh_url' => env('STRIPE_ONBOARDING_REFRESH_URL', 'http://localhost:3000/restaurant/settings/payments/refresh'),
    ],
];
