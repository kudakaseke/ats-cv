<?php

return [
    // Exact hostnames, without scheme, path or port. Never trust arbitrary Host headers.
    'hosts' => array_values(array_unique(array_filter([
        parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST),
        ...(env('APP_ENV', 'production') === 'local' ? ['localhost', '127.0.0.1', '[::1]'] : []),
        ...array_map('trim', explode(',', (string) env('TRUSTED_HOSTS', ''))),
    ]))),
];
