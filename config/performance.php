<?php

return [
    // Production needs a cron/worker. Local development remains immediately usable.
    'background_connection' => env('BACKGROUND_QUEUE_CONNECTION', env('APP_ENV', 'production') === 'production' ? env('QUEUE_CONNECTION', 'database') : 'sync'),
    'queue_payment_checks' => env('QUEUE_PAYMENT_CHECKS', env('APP_ENV', 'production') === 'production'),
];
