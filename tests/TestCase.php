<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests render Inertia pages whose root Blade calls @vite. CI runs
        // tests without a frontend build, so stub Vite to avoid a manifest-not-found
        // 500. Real asset wiring is covered by the frontend build job.
        $this->withoutVite();
    }
}
