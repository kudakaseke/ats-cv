<?php

return [
    // Enable only after confirming the merchant currency and public callback URL.
    'paynow' => [
        'enabled' => env('PAYNOW_ENABLED', false),
        'mode' => env('PAYNOW_MODE', 'test'),
        'local_test_credits' => env('PAYNOW_LOCAL_TEST_CREDITS', false),
        'callback_url' => env('PAYNOW_CALLBACK_URL'),
        'test_email' => env('PAYNOW_TEST_EMAIL'),
        'integration_id' => env(env('PAYNOW_MODE', 'test') === 'live' ? 'PAYNOW_LIVE_INTEGRATION_ID' : 'PAYNOW_TEST_INTEGRATION_ID', null) ?: env('PAYNOW_INTEGRATION_ID'),
        'integration_key' => env(env('PAYNOW_MODE', 'test') === 'live' ? 'PAYNOW_LIVE_INTEGRATION_KEY' : 'PAYNOW_TEST_INTEGRATION_KEY', null) ?: env('PAYNOW_INTEGRATION_KEY'),
        'currency' => 'USD',
        'live_currency_confirmed' => env('PAYNOW_LIVE_CURRENCY_CONFIRMED', false),
        // No proration: confirmed plan changes begin after all existing paid time.
        'plan_change_policy' => env('PAYNOW_PLAN_CHANGE_POLICY', 'end_of_cycle'),
    ],
    // Repeat purchases add credits now; access periods remain sequential.
    'subscription_credit_timing' => env('SUBSCRIPTION_CREDIT_TIMING', 'immediate'),
    'business_name' => env('BILLING_BUSINESS_NAME', 'ATS CV Detector'),
    'business_address' => env('BILLING_BUSINESS_ADDRESS', ''),
];
