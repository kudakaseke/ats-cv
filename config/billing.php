<?php

$dayIntegrationId = env('PAYNOW_DAY_SITE_INTEGRATION_ID');
$dayIntegrationKey = env('PAYNOW_DAY_SITE_INTEGRATION_KEY');
$hasDedicatedDayIntegration = $dayIntegrationId || $dayIntegrationKey;

return [
    // Enable only after confirming the merchant currency and public callback URL.
    'paynow' => [
        'enabled' => env('PAYNOW_ENABLED', false),
        'mode' => env('PAYNOW_MODE', 'test'),
        'local_test_credits' => env('PAYNOW_LOCAL_TEST_CREDITS', false),
        'callback_url' => env('PAYNOW_CALLBACK_URL'),
        'return_url' => env('PAYNOW_RETURN_URL'),
        'test_email' => env('PAYNOW_TEST_EMAIL'),
        'integration_id' => env(env('PAYNOW_MODE', 'test') === 'live' ? 'PAYNOW_LIVE_INTEGRATION_ID' : 'PAYNOW_TEST_INTEGRATION_ID', null) ?: env('PAYNOW_INTEGRATION_ID'),
        'integration_key' => env(env('PAYNOW_MODE', 'test') === 'live' ? 'PAYNOW_LIVE_INTEGRATION_KEY' : 'PAYNOW_TEST_INTEGRATION_KEY', null) ?: env('PAYNOW_INTEGRATION_KEY'),
        'day_button_key' => env('PAYNOW_DAY_BUTTON_KEY'),
        'day_site' => [
            'integration_id' => $hasDedicatedDayIntegration ? $dayIntegrationId : env('PAYNOW_TEST_INTEGRATION_ID'),
            'integration_key' => $hasDedicatedDayIntegration ? $dayIntegrationKey : env('PAYNOW_TEST_INTEGRATION_KEY'),
            'test_email' => env('PAYNOW_DAY_SITE_TEST_EMAIL'),
            'callback_url' => env('PAYNOW_DAY_SITE_CALLBACK_URL'),
        ],
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
