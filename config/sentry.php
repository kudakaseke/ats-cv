<?php

use App\Services\SentryIncidentReporter;

return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),
    'environment' => env('SENTRY_ENVIRONMENT') ?: env('APP_ENV', 'production'),
    'release' => env('SENTRY_RELEASE'),
    'send_default_pii' => false,
    'default_integrations' => false,
    'integrations' => SentryIncidentReporter::class.'::integrations',
    'before_send' => [SentryIncidentReporter::class, 'beforeSend'],
    'sample_rate' => 1.0,
    'traces_sample_rate' => null,
    'profiles_sample_rate' => null,
    'enable_logs' => false,
    'enable_metrics' => false,
    'send_client_reports' => false,
    'attach_stacktrace' => false,
    'max_request_body_size' => 'none',
    'max_breadcrumbs' => 0,
    'context_lines' => 0,
    'http_connect_timeout' => 2,
    'http_timeout' => 3,
    'breadcrumbs' => array_fill_keys(['logs', 'cache', 'livewire', 'sql_queries', 'sql_bindings', 'queue_info', 'command_info', 'http_client_requests', 'notifications'], false),
    'tracing' => array_fill_keys(['default_integrations', 'queue_job_transactions', 'queue_jobs', 'sql_queries', 'sql_bindings', 'views', 'livewire', 'http_client_requests', 'cache', 'redis_commands', 'notifications', 'gen_ai'], false),
];
