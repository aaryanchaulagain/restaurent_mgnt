<?php

return [
    'default_currency' => env('RESTAURANT_DEFAULT_CURRENCY', 'AUD'),
    'default_timezone' => env('RESTAURANT_DEFAULT_TIMEZONE', 'Australia/Sydney'),
    'default_country' => 'AU',
    'default_commission_rate' => env('PARTNER_DEFAULT_COMMISSION_RATE', '12.50'),
    'application_expiry_days' => (int) env('PARTNER_APPLICATION_EXPIRY_DAYS', 90),
    'terms_version' => env('PARTNER_TERMS_VERSION', '2026-07-01'),

    'max_document_bytes' => (int) env('PARTNER_MAX_DOCUMENT_BYTES', 5 * 1024 * 1024),
    'allowed_document_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
    'allowed_document_mimes' => [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ],
    'blocked_document_extensions' => [
        'php', 'exe', 'js', 'html', 'htm', 'svg', 'bat', 'cmd', 'sh', 'phtml', 'phar',
    ],

    'required_documents' => [
        'business_registration',
        'food_business_licence',
        'owner_identification',
    ],

    'document_types' => [
        'business_registration',
        'abn_document',
        'food_business_licence',
        'owner_identification',
        'public_liability_insurance',
        'bank_account_evidence',
        'other',
    ],

    'service_types' => [
        'delivery',
        'pickup',
        'dine_in',
        'delivery_and_pickup',
        'all',
    ],

    'business_types' => [
        'sole_trader',
        'partnership',
        'company',
        'trust',
        'other',
    ],

    'commission_types' => [
        'percentage',
        'fixed',
        'percentage_plus_fixed',
        'custom',
    ],

    'settlement_frequencies' => [
        'daily',
        'weekly',
        'fortnightly',
        'monthly',
    ],

    'rejection_categories' => [
        'incomplete_information',
        'invalid_documents',
        'unsupported_location',
        'compliance_issue',
        'duplicate_business',
        'commercial_decision',
        'other',
    ],

    'australian_states' => [
        'NSW' => 'New South Wales',
        'VIC' => 'Victoria',
        'QLD' => 'Queensland',
        'SA' => 'South Australia',
        'WA' => 'Western Australia',
        'TAS' => 'Tasmania',
        'ACT' => 'Australian Capital Territory',
        'NT' => 'Northern Territory',
    ],

    'required_application_fields' => [
        'legal_business_name',
        'trading_name',
        'business_type',
        'abn',
        'business_email',
        'business_phone',
        'description',
        'primary_contact_name',
        'primary_contact_email',
        'primary_contact_phone',
        'cuisine_summary',
        'service_type',
    ],
];
