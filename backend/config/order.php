<?php

return [
    'acceptance_timeout_minutes' => (int) env('ORDER_ACCEPTANCE_TIMEOUT_MINUTES', 10),

    'customer_cancellable_statuses' => [
        'awaiting_restaurant',
    ],

    'rejection_reasons' => [
        'item_unavailable',
        'restaurant_too_busy',
        'closing_soon',
        'cannot_fulfil_request',
        'incorrect_menu_information',
        'other',
    ],
];
