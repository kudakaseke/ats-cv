<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class RedisCheckTest extends TestCase
{
    public function test_probe_checks_cache_and_lock_and_cleans_up(): void
    {
        $store = Mockery::mock();
        $lock = Mockery::mock();
        Cache::shouldReceive('store')->once()->with('redis')->andReturn($store);
        $store->shouldReceive('lock')->once()->andReturn($lock);
        $store->shouldReceive('put')->once()->andReturn(true);
        $store->shouldReceive('get')->once()->andReturn('ok');
        $lock->shouldReceive('get')->once()->andReturn(true);
        $lock->shouldReceive('release')->once()->andReturn(true);
        $store->shouldReceive('forget')->once()->andReturn(true);

        $this->artisan('app:redis-check')->expectsOutput('Redis cache read/write and lock checks passed.')->assertSuccessful();
    }

    public function test_probe_reports_connection_failure_without_exposing_exception_details(): void
    {
        Cache::shouldReceive('store')->once()->with('redis')->andThrow(new \RuntimeException('private connection details'));

        $this->artisan('app:redis-check')
            ->expectsOutput('Redis cache or lock check failed. Check the client, network and credentials in the server configuration.')
            ->doesntExpectOutput('private connection details')->assertFailed();
    }
}
