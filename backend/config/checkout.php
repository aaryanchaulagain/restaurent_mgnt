<?php

return [
    'quote_expiry_minutes' => (int) env('CHECKOUT_QUOTE_EXPIRY_MINUTES', 15),
    'estimated_tax_rate' => (float) env('CHECKOUT_ESTIMATED_TAX_RATE', 0),
    'estimated_service_fee_cents' => (int) env('CHECKOUT_ESTIMATED_SERVICE_FEE_CENTS', 0),
];
