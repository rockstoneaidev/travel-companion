<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E8 — the UI foundation's own surface
|--------------------------------------------------------------------------
|
| The design system, the licenses screen and the PWA manifest had no test at
| all: /design renders every component, so a broken export took the whole page
| down silently, and the manifest is what makes the app installable.
|
*/

it('renders the design system demo page', function () {
    // This page imports every component in components/app. If it 200s, the
    // barrel is intact — which is the cheapest possible smoke test of E8.
    $this->get('/design')->assertOk();
});

it('serves the attribution screen, because ODbL is not a footer', function () {
    $this->get('/licenses')->assertOk();
});

it('serves an installable PWA manifest carrying the configured app name', function () {
    $response = $this->get('/manifest.webmanifest');

    $response->assertOk();
    $response->assertHeader('content-type', 'application/manifest+json');

    $manifest = $response->json();

    expect($manifest['name'])->toBe(config('app.name'))
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['icons'])->toHaveCount(3);
});

it('never hard-codes the market-facing name into the bundle', function () {
    // The name is interim (CLAUDE.md): it lives in APP_NAME and travels through
    // Inertia's shared props. A hard-coded string here becomes a find-and-replace
    // across the codebase the day the brand is decided.
    $sources = collect(glob(resource_path('js/**/*.tsx')) ?: [])
        ->merge(glob(resource_path('js/**/**/*.tsx')) ?: [])
        ->map(fn (string $path): string => (string) file_get_contents($path))
        ->implode("\n");

    expect($sources)->not->toContain('Travel Companion');
});

it('keeps the service worker free of the dropped codename, and its cache tied to the build', function () {
    // The TEMPLATE, not public/sw.js: the worker is generated at build time
    // (scripts/build-sw.mjs) and public/sw.js therefore does not exist in a checkout
    // that has not run `npm run build` — including CI's backend job.
    $sw = (string) file_get_contents(base_path('resources/sw/sw.template.js'));

    /*
     * The cache key is what actually matters: a stale key means a stale shell. It used
     * to be the constant 'app-shell-v2' and that is what wedged in-app navigation on
     * staging — the activate handler only evicts caches that are NOT the current name,
     * so a name that never changes evicts nothing, ever, and a worker from an old
     * deploy served its own stale bodies forever.
     *
     * So the placeholder is the invariant now: the build stamps the manifest hash into
     * it, and a hand-written constant here would silently bring the bug back.
     */
    expect($sw)->toContain("const SHELL_CACHE = 'app-shell-__BUILD_ID__'")
        ->and($sw)->not->toContain('passo');
});
