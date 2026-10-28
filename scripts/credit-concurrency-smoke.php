<?php

use App\Models\User;
use App\Services\AiCreditService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (($argv[1] ?? '') === '--worker') {
    $user = User::findOrFail((int) $argv[2]);
    // Refuse arbitrary real accounts even if this helper is invoked manually.
    if (! str_starts_with($user->email, 'concurrency-smoke-') || ! str_ends_with($user->email, '@example.invalid')) {
        exit(3);
    }
    try {
        $credits = app(AiCreditService::class);
        $operation = $credits->reserve($user, 'analyse', (string) Str::uuid(), ['fixture' => true, 'distinct_task' => (string) Str::uuid()]);
        $credits->finish($user, $operation->id, ['fixture' => true]);
        exit(0);
    } catch (ValidationException) {
        exit(2);
    }
}

if (app()->isProduction() || config('database.default') !== 'mysql') {
    fwrite(STDERR, "Run only against your local/staging MySQL database, not production.\n");
    exit(1);
}
$user = new User;
$user->forceFill(['name' => 'Concurrency fixture', 'email' => 'concurrency-smoke-'.Str::uuid().'@example.invalid',
    'password' => Str::random(64), 'email_verified_at' => now()])->save();
$processes = [];
try {
    $credits = app(AiCreditService::class);
    $cost = config('ai_credits.costs.analyse');
    $target = $cost * 12;
    $credits->topup($user, $target - $credits->wallet($user)['credits'], 'concurrency-fixture:'.$user->id);
    for ($index = 0; $index < 16; $index++) {
        $process = new Process([PHP_BINARY, __FILE__, '--worker', (string) $user->id], base_path());
        $process->setTimeout(60)->start();
        $processes[] = $process;
    }
    $results = array_map(fn (Process $process) => $process->wait(), $processes);
    $accepted = count(array_filter($results, fn ($status) => $status === 0));
    $rejected = count(array_filter($results, fn ($status) => $status === 2));
    $balance = $credits->wallet($user)['credits'];
    if ($accepted !== 12 || $rejected !== 4 || $balance !== 0) {
        throw new RuntimeException('Unexpected concurrent credit result; inspect private local logs.');
    }
    echo "PASS: 16 concurrent MySQL processes; 12 funded operations, 4 insufficient-credit rejections, final balance 0. No provider calls.\n";
} finally {
    foreach ($processes as $process) {
        if ($process->isRunning()) {
            $process->stop();
        }
    }
    $user->delete();
}
