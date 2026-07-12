<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests render Inertia pages whose root Blade calls @vite. CI runs
        // tests without a frontend build, so stub Vite to avoid a manifest-not-found
        // 500. Real asset wiring is covered by the frontend build job.
        $this->withoutVite();

        // No test may talk to the outside world.
        //
        // This is not tidiness. The queue is SYNC in tests, so a job dispatched
        // from the ranking path runs inline — and without this guard the suite was
        // quietly making real, paid Gemini calls, with assertions that depended on
        // what a model happened to say. A stray request now fails loudly instead of
        // succeeding expensively.
        //
        // Adapters and the LLM client are exercised against RECORDED fixtures
        // (tests/Fixtures/Sources) or a fake; a test that needs a response fakes it
        // explicitly with Http::fake().
        Http::preventStrayRequests();
    }
}
