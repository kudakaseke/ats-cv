<?php

// Isolated MySQL concurrency audit. No provider traffic or customer records.
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CreditBillingController;
use App\Models\BillingPayment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AiCreditService;
use App\Services\Billing\PaymentService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$worker = ($argv[1] ?? '') === 'worker';
$database = $worker ? ($argv[2] ?? '') : 'ats_cv_payment_audit_'.bin2hex(random_bytes(6));
if (! preg_match('/^ats_cv_payment_audit_[a-f0-9]{12}$/D', $database)) {
    throw new RuntimeException('Invalid isolated audit database name.');
}
$created = false;
$failed = false;
try {
    if (! $worker) {
        DB::connection('mysql')->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created = true;
    }
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => $database,
        'database.connections.mysql.url' => null, 'cache.default' => 'database', 'cache.prefix' => $database,
        'cache.stores.database.connection' => 'mysql', 'cache.stores.database.lock_connection' => 'mysql',
        'cache.stores.database.table' => 'cache', 'cache.stores.database.lock_table' => 'cache_locks',
        'mail.default' => 'array', 'queue.default' => 'sync', 'billing.paynow.mode' => 'live',
        'billing.paynow.enabled' => true, 'billing.paynow.live_currency_confirmed' => true,
        'billing.paynow.integration_id' => 'audit', 'billing.paynow.integration_key' => 'audit-only-fake-key',
        'billing.subscription_credit_timing' => 'immediate', 'billing.paynow.plan_change_policy' => 'end_of_cycle']);
    $app->instance('env', 'testing');
    DB::purge('mysql');
    Http::preventStrayRequests();
    $signed = function (array $data): string {
        $data['hash'] = strtoupper(hash('sha512', implode('', $data).'audit-only-fake-key'));

        return http_build_query($data);
    };
    Http::fake(function ($request) use ($signed) {
        usleep(100000);
        if (str_contains($request->url(), '/initiatetransaction')) {
            DB::table('audit_gateway_calls')->insert(['reference' => $request['reference']]);

            return Http::response($signed(['status' => 'Ok', 'browserurl' => 'https://www.paynow.co.zw/Payment/Confirm',
                'pollurl' => 'https://www.paynow.co.zw/interface/checkpayment?reference='.$request['reference']]));
        }
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
        $payment = BillingPayment::where('reference', $query['reference'] ?? '')->firstOrFail();

        return Http::response($signed(['reference' => $payment->reference,
            'amount' => number_format($payment->amount_cents / 100, 2, '.', ''),
            'paynowreference' => 'AUDIT'.$payment->id, 'status' => 'Paid']));
    });
    if ($worker) {
        $action = $argv[3];
        $id = (int) $argv[4];
        if ($action === 'verify') {
            for ($attempt = 0; ; $attempt++) {
                try {
                    app(PaymentService::class)->refresh(BillingPayment::findOrFail($id));
                    break;
                } catch (RuntimeException $exception) {
                    if ($exception->getMessage() !== 'Payment verification is already in progress.' || $attempt >= 100) {
                        throw $exception;
                    }
                    usleep(100000);
                }
            }
        } elseif (in_array($action, ['checkout', 'topup'], true)) {
            $request = Request::create('/billing/paynow/monthly', 'POST', ['billing_consent' => 1]);
            $request->setUserResolver(fn () => User::findOrFail($id));
            $request->setLaravelSession(app('session')->driver('array'));
            if ($action === 'checkout') {
                app(BillingController::class)->start($request, 'paynow', 'monthly');
            } else {
                $request->merge(['pack' => 'monthly', 'provider' => 'paynow']);
                app(CreditBillingController::class)->topup($request);
            }
        } elseif ($action === 'spend') {
            try {
                app(AiCreditService::class)->reserve(User::findOrFail($id), 'revamp', (string) Str::uuid(), ['audit' => true]);
                echo 'spent';
            } catch (ValidationException $exception) {
                echo 'insufficient';
            }
        } else {
            throw new RuntimeException('Unknown audit action.');
        }
        exit(0);
    }
    Artisan::call('migrate', ['--force' => true]);
    Artisan::call('db:seed', ['--class' => SubscriptionPlanSeeder::class, '--force' => true]);
    Schema::create('audit_gateway_calls', function ($table) {
        $table->id();
        $table->string('reference');
    });
    $check = function (bool $condition, string $message): void {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    };
    $wave = function (array $tasks) use ($database, $check): array {
        $outputs = [];
        foreach (array_chunk($tasks, 8) as $chunk) {
            $processes = [];
            foreach ($chunk as [$action, $id]) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, __FILE__, 'worker', $database, $action, (string) $id],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
                $check(is_resource($process), 'Could not start audit worker.');
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            foreach ($processes as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($process);
                $check($exit === 0, 'Audit worker failed: '.substr($error.$output, 0, 500));
                $outputs[] = trim($output);
            }
        }

        return $outputs;
    };
    $plan = SubscriptionPlan::where('slug', 'monthly')->firstOrFail();
    $payment = function (?User $user = null) use ($plan): BillingPayment {
        $reference = (string) Str::uuid();

        return BillingPayment::create(['reference' => $reference, 'user_id' => ($user ?? User::factory()->create())->id,
            'subscription_plan_id' => $plan->id, 'provider' => 'paynow', 'mode' => 'live', 'currency' => 'USD',
            'amount_cents' => $plan->price_cents, 'billing_interval' => $plan->billing_interval, 'credits' => 150,
            'poll_url' => 'https://www.paynow.co.zw/interface/checkpayment?reference='.$reference]);
    };
    $start = microtime(true);
    $duplicate = $payment();
    $wave(array_fill(0, 8, ['verify', $duplicate->id]));
    $check(Subscription::where('user_id', $duplicate->user_id)->count() === 1, 'Duplicate fulfillment.');
    $check(DB::table('billing_receipts')->where('billing_payment_id', $duplicate->id)->count() === 1, 'Duplicate receipt.');
    $check(app(AiCreditService::class)->wallet(User::findOrFail($duplicate->user_id))['credits'] === 150, 'Duplicate credits.');
    echo "Duplicate verification: 8 workers, one subscription/receipt, 150 credits.\n";
    $payments = collect(range(1, 16))->map(fn () => $payment());
    $wave($payments->map(fn ($p) => ['verify', $p->id])->all());
    foreach ($payments as $p) {
        $check($p->fresh()->status === 'paid' && app(AiCreditService::class)->wallet(User::findOrFail($p->user_id))['credits'] === 150, 'Cross-user fulfillment failed.');
    }
    echo "Independent users: 16 payments, all paid, 150 credits each.\n";
    $buyer = User::factory()->create();
    $wave(array_fill(0, 8, ['checkout', $buyer->id]));
    $check(BillingPayment::where('user_id', $buyer->id)->count() === 1, 'Duplicate checkout: '.json_encode(BillingPayment::where('user_id', $buyer->id)->get(['id', 'status', 'failure_reason'])->toArray()));
    $check(DB::table('audit_gateway_calls')->count() === 1, 'Duplicate initialization.');
    echo "Concurrent checkout: 8 workers, one payment and one initialization.\n";
    $topupBuyer = User::factory()->create();
    $wave(array_fill(0, 8, ['topup', $topupBuyer->id]));
    $check(BillingPayment::where('user_id', $topupBuyer->id)->where('kind', 'topup')->count() === 1, 'Duplicate top-up checkout.');
    $check(DB::table('audit_gateway_calls')->count() === 2, 'Duplicate top-up initialization.');
    echo "Concurrent top-up: 8 workers, one payment and one initialization.\n";
    $renewalUser = User::factory()->create();
    $renewals = collect(range(1, 8))->map(fn () => $payment($renewalUser));
    $wave($renewals->map(fn ($p) => ['verify', $p->id])->all());
    $check(app(AiCreditService::class)->wallet($renewalUser)['credits'] === 1200, 'Concurrent renewal balance mismatch.');
    $periods = Subscription::where('user_id', $renewalUser->id)->orderBy('starts_at')->get();
    $check($periods->count() === 8, 'Missing renewal.');
    for ($i = 1; $i < 8; $i++) {
        $check($periods[$i]->starts_at->equalTo($periods[$i - 1]->ends_at), 'Overlapping renewal.');
    }
    echo "Concurrent renewals: 8 payments, 1200 credits, sequential periods.\n";
    $results = $wave(array_fill(0, 20, ['spend', $duplicate->user_id]));
    $check(count(array_filter($results, fn ($result) => $result === 'spent')) === 15, 'Concurrent spend count mismatch.');
    $check(count(array_filter($results, fn ($result) => $result === 'insufficient')) === 5, 'Missing insufficient-credit rejection.');
    $check(app(AiCreditService::class)->wallet(User::findOrFail($duplicate->user_id))['credits'] === 0, 'Wallet overspent.');
    echo "Concurrent spending: 15 accepted, 5 rejected, zero balance.\n";
    echo 'PASS in '.round(microtime(true) - $start, 2)."s; max 8 PHP workers; simulated provider.\n";
} catch (Throwable $exception) {
    // No connection details, credentials, provider responses or customer data.
    fwrite(STDERR, 'FAIL '.get_class($exception).': '.($exception instanceof QueryException ? 'Database audit operation failed.' : $exception->getMessage())."\n");
    $failed = true;
} finally {
    if (! $worker && $created) {
        DB::connection('mysql')->statement('DROP DATABASE `'.$database.'`');
        echo "Isolated audit database removed.\n";
    }
}
exit($failed ? 1 : 0);
