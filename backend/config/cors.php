<?php

$frontend = env('FRONTEND_URL', 'http://localhost:3000');
$isProd = in_array(env('APP_ENV'), ['production', 'prod'], true);

$origins = array_filter([
    $frontend,
]);

// Localhost origins only outside production (credentialed CORS must not use wildcards).
if (! $isProd) {
    $origins = array_values(array_unique(array_filter([
        ...$origins,
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ])));
}

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => $isProd
        ? []
        : [
            '#^https://[a-z0-9-]+\.trycloudflare\.com$#',
        ],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 0,

    'supports_credentials' => true,
];
