<?php

return [
    'owner_email' => env('ADMIN_OWNER_EMAIL'),
    'ip_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_IP_ALLOWLIST', ''))))),
    'dashboard_preview_without_2fa' => filter_var(env('ADMIN_DASHBOARD_PREVIEW_WITHOUT_2FA', false), FILTER_VALIDATE_BOOL),
];
