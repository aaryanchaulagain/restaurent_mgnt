<?php

return [
    'cookie_name' => env('CART_COOKIE_NAME', 'suvakamana_cart'),
    'guest_ttl_days' => (int) env('CART_GUEST_TTL_DAYS', 14),
    'max_quantity_per_line' => (int) env('CART_MAX_QUANTITY', 99),
    'max_lines' => (int) env('CART_MAX_LINES', 50),
];
