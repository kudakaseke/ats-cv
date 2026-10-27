<?php

namespace App\Console\Commands;

use App\Services\RemoteDocumentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

class CheckDeployment extends Command
{
    protected $signature = 'app:deployment-check';

    protected $description = 'Check production settings and required document-tool capabilities before launch';

    public function handle(): int
    {
        try {
            $workerSeen = Cache::has('background-worker-heartbeat');
        } catch (\Throwable) {
            $workerSeen = false;
        }
        $checks = [
            'Patched PHP runtime' => $this->runtimeIsPatched(),
            'Production environment' => app()->isProduction(),
            'Debug disabled' => ! config('app.debug'),
            'Canonical HTTPS URL' => parse_url(config('app.url'), PHP_URL_SCHEME) === 'https',
            'Secure, HttpOnly session cookies' => config('session.secure') && config('session.http_only'),
            'Durable background connection' => in_array(config('performance.background_connection'), ['database', 'redis'], true),
            'Queued payment polling' => (bool) config('performance.queue_payment_checks'),
            'Recent bounded-worker heartbeat' => $workerSeen,
            'Private logs/storage writable' => is_writable(storage_path('logs')) && is_writable(storage_path('app/private')),
            'Production assets built' => is_file(public_path('build/manifest.json')) && ! is_file(public_path('hot')),
            'Configured host allowlist' => in_array(parse_url(config('app.url'), PHP_URL_HOST), config('security.hosts'), true),
            'No wildcard proxy trust' => ! in_array('*', array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))), true),
            'Shared Redis rate-limit store' => config('cache.stores.'.config('cache.limiter').'.driver') === 'redis',
        ];
        try {
            DB::select('SELECT 1');
            $checks['Database and queue schema available'] = Schema::hasTable('jobs') && Schema::hasTable('failed_jobs');
            $checks['Dedicated database identity'] = ! in_array(strtolower((string) config('database.connections.'.config('database.default').'.username')), ['', 'root'], true);
        } catch (\Throwable) {
            $checks['Database and queue schema available'] = false;
        }
        if (config('cv_exports.driver') === 'remote') {
            try {
                $checks['Authenticated remote document service ready'] = app(RemoteDocumentService::class)->healthy();
            } catch (\Throwable) {
                $checks['Authenticated remote document service ready'] = false;
            }
        } elseif (config('cv_exports.driver') === 'local') {
            $checks['Python PDF parser available'] = $this->runs([config('cv_exports.python'), '-c', 'import pymupdf']);
            $checks['Node, Playwright and Chrome launch'] = $this->runs([
                config('cv_exports.node'), '--input-type=module', '-e',
                'import { chromium } from "@playwright/test"; const b = await chromium.launch({channel: process.argv[1], headless:true}); await b.close();',
                config('cv_exports.browser'),
            ]);
        } else {
            $checks['Valid document driver'] = false;
        }
        foreach ($checks as $name => $ok) {
            $this->line(($ok ? '[PASS] ' : '[FAIL] ').$name);
        }
        $this->warn('Also verify the real web PHP runtime, public/ document root, cron execution, TLS, backups, provider credentials and hosting resource limits. This command does not prove 1,000-user capacity.');
        $this->warn('DDoS readiness requires an edge WAF/CDN, origin firewall restrictions and authorised staging load tests. PHP rate limits do not prove resistance to 14,000 requests/second.');

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    private function runs(array $command): bool
    {
        try {
            return (new Process($command, base_path()))->setTimeout(20)->run() === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function runtimeIsPatched(): bool
    {
        $minimums = ['8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'];
        $minimum = $minimums[PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION] ?? null;

        return $minimum !== null && version_compare(PHP_VERSION, $minimum, '>=');
    }
}
