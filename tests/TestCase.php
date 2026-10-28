<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Tests must never spend a real provider key or contact unmocked APIs.
        \Illuminate\Support\Facades\Http::preventStrayRequests();
    }
}
