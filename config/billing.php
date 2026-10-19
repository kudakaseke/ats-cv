<?php

return [
    // Enable only after confirming the merchant currency and public callback URL.
    'paynow' => [
        'enabled' => env('PAYNOW_ENABLED', false),
        'mode' => env('PAYNOW_MODE', env('APP_ENV') === 'local' ? 'test' : 'live'),
        'callback_url' => env('PAYNOW_CALLBACK_URL'),
        'test_email' => env('PAYNOW_TEST_EMAIL'),
        'integration_id' => env('PAYNOW_INTEGRATION_ID'),
        'integration_key' => env('PAYNOW_INTEGRATION_KEY'),
        'currency' => 'USD',
    ],
    'paypal' => [
        'enabled' => env('PAYPAL_ENABLED', false),
        'mode' => env('PAYPAL_MODE', 'sandbox'),
        'client_id' => env(env('PAYPAL_MODE', 'sandbox') === 'live' ? 'PAYPAL_LIVE_CLIENT_ID' : 'PAYPAL_SANDBOX_CLIENT_ID', env('PAYPAL_CLIENT_ID')),
        'client_secret' => env(env('PAYPAL_MODE', 'sandbox') === 'live' ? 'PAYPAL_LIVE_CLIENT_SECRET' : 'PAYPAL_SANDBOX_CLIENT_SECRET', env('PAYPAL_CLIENT_SECRET')),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        'plans' => ['monthly' => env('PAYPAL_MONTHLY_PLAN_ID'), 'annual' => env('PAYPAL_ANNUAL_PLAN_ID')],
    ],
];
