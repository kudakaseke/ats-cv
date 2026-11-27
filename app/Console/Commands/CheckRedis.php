<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CheckRedis extends Command
{
    protected $signature = 'app:redis-check';

    protected $description = 'Check the configured Redis cache and lock connections without changing the active cache store';

    public function handle(): int
    {
        $key = 'redis-check:'.Str::uuid();
        $store = null;
        $lock = null;
        $acquired = false;

        try {
            $store = Cache::store('redis');
            $lock = $store->lock($key.':lock', 30);
            if (! $store->put($key, 'ok', 30) || $store->get($key) !== 'ok') {
                throw new \RuntimeException('Cache check failed.');
            }
            $acquired = $lock->get();
            if (! $acquired) {
                throw new \RuntimeException('Lock check failed.');
            }
            $this->info('Redis cache read/write and lock checks passed.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Redis cache or lock check failed. Check the client, network and credentials in the server configuration.');

            return self::FAILURE;
        } finally {
            try {
                if ($acquired) {
                    $lock->release();
                }
                $store?->forget($key);
            } catch (\Throwable) {
                // Probe keys expire automatically if cleanup cannot reach Redis.
            }
        }
    }
}
