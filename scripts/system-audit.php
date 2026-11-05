<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$provider = config('services.cv_ai.provider');
$counts = fn (string $table, array $columns) => DB::table($table)->select($columns)
    ->selectRaw('count(*) as total')->groupBy($columns)->get();
$summary = [
    'environment' => app()->environment(),
    'debug' => config('app.debug'),
    'ai' => ['provider' => $provider, 'model' => config('services.'.$provider.'.model'),
        'key_configured' => filled(config('services.'.$provider.'.key'))],
    'paynow' => ['enabled' => config('billing.paynow.enabled'), 'mode' => config('billing.paynow.mode'),
        'credentials_configured' => filled(config('billing.paynow.integration_id')) && filled(config('billing.paynow.integration_key')),
        'live_currency_confirmed' => config('billing.paynow.live_currency_confirmed'),
        'callback_override_configured' => filled(config('billing.paynow.callback_url'))],
    'documents' => ['driver' => config('cv_exports.driver'),
        'local_python_available' => is_file(config('cv_exports.python')),
        'remote_configured' => filled(config('cv_exports.remote_url')) && filled(config('cv_exports.remote_key'))],
    'plans' => DB::table('subscription_plans')->select('slug', 'price_cents', 'currency', 'billing_interval', 'is_active')->get(),
    'payments' => $counts('billing_payments', ['provider', 'mode', 'status']),
    'legacy_open_agreements' => DB::table('billing_payments')->where('provider', '!=', 'paynow')->where('recurring', true)
        ->where(fn ($query) => $query->whereNull('recurring_status')
            ->orWhereNotIn('recurring_status', ['CANCELLED', 'EXPIRED', 'SETUP_FAILED', 'DISABLED', 'COMPLETE']))->count(),
    'ai_operations' => $counts('ai_operations', ['feature', 'status']),
    'ai_usage' => DB::table('ai_usage_logs')->select('feature', 'provider', 'model', 'status')
        ->selectRaw('count(*) as operations, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, sum(credits_charged) as credits_charged')
        ->groupBy('feature', 'provider', 'model', 'status')->get(),
    'stale_ai_reservations' => DB::table('ai_operations')->where('status', 'reserved')->where('created_at', '<', now()->subMinutes(10))->count(),
    'queue' => ['connection' => config('queue.default'), 'payment_checks_queued' => config('performance.queue_payment_checks'),
        'pending_jobs' => $counts('jobs', ['queue']), 'failed_jobs' => DB::table('failed_jobs')->count()],
    'unprocessed_payment_notifications' => DB::table('payment_notifications')->whereNull('processed_at')->count(),
    'routes' => collect(Route::getRoutes())->map(fn ($route) => $route->uri())
        ->filter(fn ($uri) => str_contains($uri, 'billing') || str_contains($uri, 'credits') || str_contains($uri, 'webhook'))->values(),
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
