<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class WorkBackground extends Command
{
    protected $signature = 'app:work';

    protected $description = 'Run a bounded, non-overlapping background worker suitable for a once-per-minute cron';

    public function handle(): int
    {
        $lock = Cache::lock('bounded-background-worker', 240);
        if (! $lock->get()) {
            $this->info('A background worker is already running.');

            return self::SUCCESS;
        }
        try {
            if (config('performance.background_connection') === 'sync') {
                $this->warn('Background connection is sync; production must use database or redis.');

                return self::FAILURE;
            }
            Cache::put('background-worker-heartbeat', now()->toIso8601String(), now()->addMinutes(5));

            return $this->call('queue:work', [
                'connection' => config('performance.background_connection'),
                '--queue' => 'mail,payments,default', '--stop-when-empty' => true,
                '--max-time' => 45, '--timeout' => 180, '--tries' => 3, '--memory' => 128,
            ]);
        } finally {
            $lock->release();
        }
    }
}
