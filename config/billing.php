<?php

return [
    'environment' => env('BILLING_ENVIRONMENT', 'test'),
    // Backend benefits are implemented; the web integration and sales readiness review must finish first.
    'live_sales_enabled' => false,
    'offer_version' => '2026-10-29',
    'stripe_secret' => env('STRIPE_SECRET_KEY'),
    'stripe_account' => env('STRIPE_ACCOUNT_ID'),
    'stripe_version' => '2024-06-20',
    'webhook_secrets' => array_values(array_filter(explode(',', (string) env('STRIPE_WEBHOOK_SECRETS', '')))),
    'portal_configuration' => env('STRIPE_PORTAL_CONFIGURATION'),
    'return_url' => env('BILLING_RETURN_URL'),
    'cancel_url' => env('BILLING_CANCEL_URL'),
    'portal_return_url' => env('BILLING_PORTAL_RETURN_URL'),
    'automatic_tax' => env('STRIPE_AUTOMATIC_TAX', false),
    'retention_days' => 2555,
    'webhook_retention_days' => 30,
    'plans' => [
        'artist_starter' => ['name' => 'Artist Starter', 'amount' => 499, 'price_id' => env('STRIPE_STARTER_PRICE_ID')],
        'artist_pro' => ['name' => 'Artist Pro', 'amount' => 1999, 'price_id' => env('STRIPE_PRO_PRICE_ID')],
    ],
];
