<?php

return [
    'enabled' => env('PAYSTACK_ENABLED', false),
    'local_test_credits' => env('PAYSTACK_LOCAL_TEST_CREDITS', false),
    'currency' => env('PAYSTACK_CURRENCY', 'ZAR'),
    'pro_amount' => env('PAYSTACK_PRO_AMOUNT_ZAR_CENTS'),
    'topup_amounts' => ['monthly' => env('PAYSTACK_MONTHLY_AMOUNT_ZAR_CENTS')],
    'secret_key' => env('PAYSTACK_SECRET_KEY'),
    // Explicit fixed charge amounts in ZAR cents; never infer an exchange rate.
    'monthly_amount' => env('PAYSTACK_MONTHLY_AMOUNT_ZAR_CENTS'),
    'annual_amount' => env('PAYSTACK_ANNUAL_AMOUNT_ZAR_CENTS'),
    // These pages were inspected and explicitly identify themselves as TEST pages.
    // Never expose them as live customer checkout links.
    'test_payment_pages' => [
        'monthly' => ['url' => 'https://paystack.shop/pay/rehfdbdk33', 'amount_cents' => 8300],
        'annual' => ['url' => 'https://paystack.shop/pay/5l1ff9py-9', 'amount_cents' => 78000],
    ],
];
