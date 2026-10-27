<?php

return [
    'welcome' => 15,
    'costs' => ['analyse' => 5, 'revamp' => 10],
    'plans' => ['free' => 15, 'monthly' => 150, 'pro' => 225, 'annual' => 2400],
    // The approved Monthly bundle is also sold as a one-time credit top-up.
    'topups' => [
        'monthly' => ['credits' => 150, 'price_cents' => 475],
    ],
];
