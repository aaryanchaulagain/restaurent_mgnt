<?php

return [
    'default_currency' => env('RESTAURANT_DEFAULT_CURRENCY', 'AUD'),
    'default_timezone' => env('RESTAURANT_DEFAULT_TIMEZONE', 'Australia/Sydney'),

    'price_levels' => ['budget', 'moderate', 'premium', 'luxury'],

    'media' => [
        'max_bytes' => (int) env('RESTAURANT_MEDIA_MAX_BYTES', 5 * 1024 * 1024),
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'logo_max_width' => 800,
        'cover_max_width' => 2400,
    ],

    'checklist_keys' => [
        'trading_name',
        'description',
        'business_email',
        'business_phone',
        'primary_address',
        'logo',
        'cover_image',
        'primary_cuisine',
        'service_type',
        'opening_hours',
        'menu_category',
        'menu_item',
        'currency',
        'timezone',
        'commission_accepted',
        'restaurant_owner',
    ],

    'spice_levels' => ['none', 'mild', 'medium', 'hot', 'extra_hot'],

    'offer_types' => [
        'percentage',
        'fixed_amount',
        'free_delivery_placeholder',
        'item_discount',
    ],

    'service_area_types' => ['postcode', 'radius', 'custom'],
];
