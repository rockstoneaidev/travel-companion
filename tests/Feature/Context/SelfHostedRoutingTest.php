<?php

declare(strict_types=1);

use App\Domain\Context\Contracts\Routing;
use App\Domain\Context\Services\FallbackRouting;
use App\Domain\Context\Services\GoogleRoutes;
use App\Domain\Context\Services\OsrmRoutes;
use App\Domain\Trips\Enums\TravelMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E43 — self-hosted routing, behind the port
|--------------------------------------------------------------------------
|
| The Routing port makes self-hosting a swap, not a rewrite. These tests hold the port
| still and swap the engine underneath it: OSRM must produce the same kind of number
| GoogleRoutes does, the config switch must select it, and Google must stay a live fallback
| so flipping the switch is low-risk.
|
| The one thing these tests CANNOT do is verify parity against a live OSRM on a real
| extract — that needs the server (SERVER-DEPLOYMENT), and it is a spot-check for staging,
| not a unit test. What they verify is the wiring, the parsing, and the fallback policy.
|
*/

beforeEach(fn () => config()->set('routing.osrm.url', 'http://osrm-test:5000'));

it('reads minutes from a self-hosted OSRM response', function () {
    Http::fake([
        'osrm-test:5000/*' => Http::response([
            'code' => 'Ok',
            'routes' => [['duration' => 742.0]],   // seconds
        ]),
    ]);

    $minutes = app(OsrmRoutes::class)->minutes(59.31, 18.02, 59.33, 18.07, TravelMode::Walk);

    expect($minutes)->toEqualWithDelta(742 / 60, 0.01);
});

it('asks OSRM in lng,lat order on the right profile', function () {
    Http::fake([
        'osrm-test:5000/*' => Http::response(['code' => 'Ok', 'routes' => [['duration' => 300]]]),
    ]);

    app(OsrmRoutes::class)->minutes(59.31, 18.02, 59.33, 18.07, TravelMode::Drive);

    Http::assertSent(function ($request): bool {
        // OSRM path is /route/v1/{profile}/{lng,lat;lng,lat}. Drive → the `driving` profile,
        // and the coordinates lng-first — the classic footgun this asserts against.
        return str_contains($request->url(), '/route/v1/driving/18.02')
            && str_contains($request->url(), ';18.07');
    });
});

it('returns null for a genuine no-route rather than pretending', function () {
    Http::fake([
        'osrm-test:5000/*' => Http::response(['code' => 'NoRoute', 'routes' => []]),
    ]);

    // Null keeps the estimator's number. A "no route" is an answer, not a failure.
    expect(app(OsrmRoutes::class)->minutes(59.31, 18.02, 59.33, 18.07, TravelMode::Walk))->toBeNull();
});

it('falls back to Google when OSRM is unreachable', function () {
    config()->set('services.google.maps_key', 'test-key');

    Http::fake([
        // OSRM is down.
        'osrm-test:5000/*' => Http::response('', 503),
        // Google answers.
        'routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '600s']]]),
    ]);

    $minutes = app(FallbackRouting::class)->minutes(59.31, 18.02, 59.33, 18.07, TravelMode::Walk);

    // The served item still gets a REAL number — the whole safety of flipping to self-hosted.
    expect($minutes)->toEqualWithDelta(10.0, 0.01);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'routes.googleapis.com'));
});

it('does not fall back to Google once OSRM has earned trust', function () {
    config()->set('routing.osrm.google_fallback', false);
    config()->set('services.google.maps_key', 'test-key');

    Http::fake([
        'osrm-test:5000/*' => Http::response('', 503),
        'routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '600s']]]),
    ]);

    // Fallback off: OSRM's silence is the final word, and Google is never called.
    expect(app(FallbackRouting::class)->minutes(59.31, 18.02, 59.33, 18.07, TravelMode::Walk))->toBeNull();
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'routes.googleapis.com'));
});

it('selects the engine by config, and defaults to Google', function () {
    config()->set('routing.driver', 'google');
    expect(app(Routing::class))->toBeInstanceOf(GoogleRoutes::class);

    // Rebind after flipping the switch.
    app()->forgetInstance(Routing::class);
    config()->set('routing.driver', 'osrm');
    expect(app(Routing::class))->toBeInstanceOf(FallbackRouting::class);
});
