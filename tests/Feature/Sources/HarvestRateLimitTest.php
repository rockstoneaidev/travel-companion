<?php

declare(strict_types=1);

use App\Support\Http\Harvest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| The budget was draining in plain sight
|--------------------------------------------------------------------------
|
| DATAtourisme publishes `x-ratelimit-limit: 1000` and `x-ratelimit-remaining`
| on every single response. Nobody read them.
|
| So when a pagination bug walked the cursor off the end of Paris and through the
| national catalogue, it spent ~1,500 calls against a 1,000-call window — and the
| first anyone knew of it was a 429 wall, an empty bucket for fifty minutes, and
| a region with no tourism-board layer at all. The number had been counting down
| in a header on every one of those requests.
|
| Two rules come out of it:
|
|   1. Say something BEFORE the bucket is empty.
|   2. When the server says how long the window is, believe it — do not spend four
|      more attempts on a wall you cannot climb.
|
*/

it('stops immediately when the window is longer than any backoff we would wait', function () {
    // The real DATAtourisme response: fifty minutes, and no Retry-After header at all.
    Http::fake([
        'api.datatourisme.fr/*' => Http::response(
            ['error_msg' => 'Too Many Requests'],
            429,
            ['x-ratelimit-limit' => '1000', 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '2997'],
        ),
    ]);

    $result = app(Harvest::class)->get('https://api.datatourisme.fr/v1/catalog');

    // ONE request, not five. Asking again while being told to stop is how a key gets
    // revoked, and five more calls into an empty bucket cannot succeed by construction.
    Http::assertSentCount(1);

    expect($result->unknown())->toBeTrue()          // never ABSENT — see Outcome
        ->and($result->attempts)->toBe(1)
        ->and($result->retryAfterSeconds)->toBe(2997)
        ->and($result->reason)->toContain('2997');
});

it('still retries a throttle it can actually wait out', function () {
    // A short window is a different animal: back off, and the next attempt succeeds.
    Http::fake([
        'example.test/*' => Http::sequence()
            ->push(['error' => 'slow down'], 429, ['Retry-After' => '1'])
            ->push(['ok' => true], 200),
    ]);

    $result = app(Harvest::class)->get('https://example.test/thing');

    expect($result->ok())->toBeTrue()
        ->and($result->attempts)->toBe(2)
        ->and($result->json('ok'))->toBeTrue();
});

it('warns while the budget is draining, not once it is gone', function () {
    Log::spy();

    // 40 of 1000 left: still answering, still successful — and already worth saying so.
    Http::fake([
        'api.datatourisme.fr/*' => Http::response(
            ['objects' => []],
            200,
            ['x-ratelimit-limit' => '1000', 'x-ratelimit-remaining' => '40', 'x-ratelimit-reset' => '600'],
        ),
    ]);

    $result = app(Harvest::class)->get('https://api.datatourisme.fr/v1/catalog');

    expect($result->ok())->toBeTrue();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'harvest: rate-limit budget nearly spent'
            && $context['remaining'] === 40
            && $context['limit'] === 1000)
        ->once();
});

it('says nothing about a source that publishes no budget', function () {
    Log::spy();

    // Overpass, Wikipedia, Wikidata — no rate-limit headers. Silence is correct: a
    // warning that fires for every source is a warning nobody reads.
    Http::fake(['overpass-api.de/*' => Http::response(['elements' => []], 200)]);

    app(Harvest::class)->get('https://overpass-api.de/api/interpreter');

    Log::shouldNotHaveReceived('warning');
});
