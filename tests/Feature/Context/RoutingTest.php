<?php

declare(strict_types=1);

use App\Domain\Context\Contracts\Routing;
use App\Domain\Trips\Enums\TravelMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E16 — Stage B: the numbers a user actually sees (PRD §10)
|--------------------------------------------------------------------------
|
| Stage A (the estimator, ±20–30%) gates hundreds of candidates for free, and
| its error is acceptable there: the reach ceiling already includes dwell, and
| the menu is alternatives, not a schedule.
|
| Stage B is different. "12 min walk" printed on a card is a promise someone is
| about to act on. Those had better be real.
|
*/

beforeEach(function () {
    config()->set('services.google.maps_key', 'test-key');
});

it('gives a real walk time, not an estimate', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '742s']]])]);

    $minutes = app(Routing::class)->minutes(59.3103, 18.0227, 59.3117, 18.0206, TravelMode::Walk);

    expect($minutes)->toBeGreaterThan(12.3)
        ->and($minutes)->toBeLessThan(12.4);   // 742s
});

it('shares a route between two people on the same corner, and not between distant ones', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '600s']]])]);

    $routing = app(Routing::class);

    // Same res-9 origin tile (~174 m), same destination, same mode → one call.
    $routing->minutes(59.31030, 18.02270, 59.3117, 18.0206, TravelMode::Walk);
    $routing->minutes(59.31031, 18.02271, 59.3117, 18.0206, TravelMode::Walk);

    Http::assertSentCount(1);

    // Half a kilometre away is a different walk, and must not reuse the answer.
    // Bucketing origins into the canonical res-8 hex would have thrown away exactly
    // the precision Stage B is being paid for.
    $routing->minutes(59.3160, 18.0300, 59.3117, 18.0206, TravelMode::Walk);

    Http::assertSentCount(2);
});

it('keeps the estimator when Google will not answer — the feed still ships', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response('boom', 503)]);

    // Null is a first-class answer (SCORING §2.5): the caller falls back to the
    // estimator's number and the user still gets a feed. A missing route is never a
    // reason to fail a request.
    expect(app(Routing::class)->minutes(59.3103, 18.0227, 59.3117, 18.0206, TravelMode::Walk))->toBeNull();
});

it('costs nothing without a key', function () {
    config()->set('services.google.maps_key', '');
    Http::fake();

    expect(app(Routing::class)->minutes(59.3103, 18.0227, 59.3117, 18.0206, TravelMode::Walk))->toBeNull();

    Http::assertNothingSent();
});

it('asks for the duration and nothing else', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['duration' => '600s']]])]);

    app(Routing::class)->minutes(59.3103, 18.0227, 59.3117, 18.0206, TravelMode::Walk);

    // A narrow field mask is both the cheap thing and the compliant thing: we do not
    // fetch what we are not allowed to keep (conventions/09).
    Http::assertSent(fn ($request): bool => $request->header('X-Goog-FieldMask') === ['routes.duration']);
});
