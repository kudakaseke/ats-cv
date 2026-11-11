<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(\Illuminate\Contracts\Auth\Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);
        // Match the fingerprint established by the real Login event.
        app(\App\Services\AccountSessions::class)->stamp($user);

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Tests must never spend a real provider key or contact unmocked APIs.
        \Illuminate\Support\Facades\Http::preventStrayRequests();
    }
}
