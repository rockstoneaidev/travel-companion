<?php

declare(strict_types=1);

use App\Support\Http\Harvest;
use App\Support\Http\Outcome;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
|--------------------------------------------------------------------------
| The ingest lane's HTTP policy (conventions/09)
|--------------------------------------------------------------------------
|
| The test that matters here is not "does it retry". It is "does a throttle
| ever get mistaken for an absence" — because that is the bug that actually
| happened, and it cost the home region its entire evidence layer.
|
| Stockholm carried 4,326 Wikipedia links and 20 stored articles because a
| 429 returned the same empty value as "no such article". Backoff would not
| have prevented it. The three-state result is what prevents it.
|
*/

beforeEach(function (): void {
    Sleep::fake();   // the backoff is real; we just refuse to wait for it
});

it('returns OK and the body on success', function (): void {
    Http::fake(['*' => Http::response(['hello' => 'world'], 200)]);

    $result = app(Harvest::class)->get('https://example.test/thing');

    expect($result->outcome)->toBe(Outcome::Ok)
        ->and($result->ok())->toBeTrue()
        ->and($result->json('hello'))->toBe('world')
        ->and($result->attempts)->toBe(1);
});

it('retries a 429 and succeeds — a throttle is not an answer', function (): void {
    Http::fake(['*' => Http::sequence()
        ->push('slow down', 429)
        ->push('slow down', 429)
        ->push(['hello' => 'world'], 200),
    ]);

    $result = app(Harvest::class)->get('https://example.test/thing');

    expect($result->ok())->toBeTrue()
        ->and($result->attempts)->toBe(3)
        ->and($result->json('hello'))->toBe('world');
});

it('reports UNKNOWN — never ABSENT — when it is throttled to exhaustion', function (): void {
    Http::fake(['*' => Http::response('slow down', 429)]);

    $result = app(Harvest::class)->get('https://example.test/thing');

    // THE REGRESSION TEST. If this ever says Absent, Stockholm empties again.
    expect($result->outcome)->toBe(Outcome::Unknown)
        ->and($result->unknown())->toBeTrue()
        ->and($result->absent())->toBeFalse()
        ->and($result->json())->toBeNull();   // and there is no body to mistake for emptiness
});

it('reports ABSENT on a 404 — the server looked, and there is nothing there', function (): void {
    Http::fake(['*' => Http::response('nope', 404)]);

    $result = app(Harvest::class)->get('https://example.test/thing');

    expect($result->outcome)->toBe(Outcome::Absent)
        ->and($result->absent())->toBeTrue()
        ->and($result->unknown())->toBeFalse()
        ->and($result->attempts)->toBe(1);   // absence is a fact; do not retry it
});

it('retries a 5xx', function (): void {
    Http::fake(['*' => Http::sequence()
        ->push('boom', 503)
        ->push(['ok' => true], 200),
    ]);

    expect(app(Harvest::class)->get('https://example.test/thing')->ok())->toBeTrue();
});

it('does not retry a 403 — asking again will not make us welcome', function (): void {
    Http::fake(['*' => Http::response('go away', 403)]);

    $result = app(Harvest::class)->get('https://example.test/thing');

    expect($result->unknown())->toBeTrue()
        ->and($result->absent())->toBeFalse()   // still not evidence of absence
        ->and($result->attempts)->toBe(1);
});

it('honours Retry-After over its own backoff formula', function (): void {
    Http::fake(['*' => Http::sequence()
        ->push('slow down', 429, ['Retry-After' => '2'])
        ->push(['ok' => true], 200),
    ]);

    app(Harvest::class)->get('https://example.test/thing');

    // The server told us 2 seconds. Believe the server, not the formula.
    Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalMilliseconds === 2_000, times: 1);
});

it('throwIfUnknown fails loudly rather than returning a maybe', function (): void {
    Http::fake(['*' => Http::response('slow down', 429)]);

    app(Harvest::class)->get('https://example.test/thing')->throwIfUnknown('test source');
})->throws(RuntimeException::class, 'test source');

it('throwIfUnknown lets an absence through — it is a fact, not a failure', function (): void {
    Http::fake(['*' => Http::response('nope', 404)]);

    $result = app(Harvest::class)->get('https://example.test/thing')->throwIfUnknown('test source');

    expect($result->absent())->toBeTrue();
});
