<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fail loudly on any HTTP request a test did not explicitly fake. Without
        // this, a test that reaches the real data.gov.my endpoint still passes —
        // slowly, non-deterministically, and only while the network is up.
        Http::preventStrayRequests();
    }
}
