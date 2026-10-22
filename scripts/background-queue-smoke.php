<?php

use App\Jobs\SendPasswordResetMail;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->isProduction()) {
    throw new RuntimeException('Run this fixture check locally or in staging only.');
}
$queue = 'isolated-worker-smoke-'.Str::uuid();
$marker = 'synthetic-reset-token-'.Str::uuid();
try {
    // No actual recipient exists, so the handler cannot send an email.
    $missingUser = ((int) DB::table('users')->max('id')) + 100000;
    Bus::dispatch((new SendPasswordResetMail($missingUser, $marker))->onConnection('database')->onQueue($queue));
    $payload = DB::table('jobs')->where('queue', $queue)->value('payload');
    if (! is_string($payload) || str_contains($payload, $marker)) {
        throw new RuntimeException('The queued secret was not encrypted.');
    }
    $status = Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue,
        '--stop-when-empty' => true, '--max-jobs' => 1, '--timeout' => 30]);
    if ($status !== 0 || DB::table('jobs')->where('queue', $queue)->exists() || DB::table('failed_jobs')->where('queue', $queue)->exists()) {
        throw new RuntimeException('Isolated background job did not complete.');
    }
    echo "PASS: real database queue encrypted a token-bearing job; isolated worker completed it. No email or provider calls.\n";
} finally {
    DB::table('jobs')->where('queue', $queue)->delete();
    DB::table('failed_jobs')->where('queue', $queue)->delete();
}
