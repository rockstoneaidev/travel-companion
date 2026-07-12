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

it('keeps the service worker free of the dropped codename', function () {
    $sw = (string) file_get_contents(public_path('sw.js'));

    // The cache key is what actually matters: a stale key means a stale shell.
    // Pinned to the NAME, not the version — bumping the version is the correct
    // response to a change in the caching contract (S11 added Inertia fetches to
    // it), and a test that forbids that is a test that punishes the right move.
    expect($sw)->toMatch("/const SHELL_CACHE = 'app-shell-v\d+'/")
        ->and($sw)->not->toContain('passo');
});
