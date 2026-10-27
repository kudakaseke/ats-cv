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
    // Merchant-supplied hosted pages used by subscription buttons in test mode.
    'test_payment_pages' => [
        'monthly' => ['url' => 'https://paystack.shop/pay/c-wsx14uqq', 'amount_cents' => 7875, 'page_id' => 2231950],
        'pro' => ['url' => 'https://paystack.shop/pay/72tl73quol', 'amount_cents' => 10400, 'page_id' => 2231953],
        'annual' => ['url' => 'https://paystack.shop/pay/c19kk2wpb7', 'amount_cents' => 78600, 'page_id' => 2231960],
    ],
];
