<?php

declare(strict_types=1);

use App\Domain\Sources\Adapters\OsmAdapter;
use App\Domain\Sources\Data\ScoutRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| OSM search() — the adaptive splitter (DATA-SOURCES §2)
|--------------------------------------------------------------------------
|
| This is the half of the adapter that had no tests, and it is the half that
| killed Nice: a region whose boxes 504 splits, and every child re-pays the
| full HTTP budget, so retrying and splitting MULTIPLY. Depth bounds breadth.
| Only a clock bounds time.
|
*/

function stockholmBox(): ScoutRequest
{
    return new ScoutRequest(regionKey: 'nice', south: 43.65, west: 7.20, north: 43.72, east: 7.30, locale: 'fr');
}

function overpassElement(int $id): array
{
    return ['type' => 'node', 'id' => $id, 'lat' => 43.7, 'lon' => 7.25, 'tags' => ['tourism' => 'museum', 'name' => "Museum {$id}"]];
}

it('splits a box that times out into smaller questions', function () {
    $calls = 0;

    Http::fake(function () use (&$calls) {
        $calls++;

        // The first top-level quadrant 504s; its children answer.
        return $calls === 1
            ? Http::response('Gateway Timeout', 504)
            : Http::response(['elements' => [overpassElement($calls)]], 200);
    });

    $elements = new OsmAdapter()->search(stockholmBox());

    // 4 quadrants, the first of which fanned out into 4 children: 3 + 4 answers.
    expect($elements)->toHaveCount(7)
        ->and($calls)->toBe(8);
});

it('never re-asks the identical question that just timed out', function () {
    $bodies = [];

    Http::fake(function ($request) use (&$bodies) {
        $bodies[] = $request->data()['data'];

        return Http::response('Gateway Timeout', 504);
    });

    try {
        new OsmAdapter()->search(stockholmBox());
    } catch (RuntimeException) {
        // every box failed — expected here
    }

    // Overpass timed out because the question was too big. The old code retried
    // the SAME query twice before splitting, burning ~190s per box to ask a
    // question that had already been answered with "too big".
    expect($bodies)->toBe(array_unique($bodies));
});

it('gives up on a patch at the depth bound instead of splitting forever', function () {
    Http::fake(['*' => Http::response('Gateway Timeout', 504)]);

    // Every box fails all the way down, so nothing comes back — and that must be
    // an exception, not an empty array. "No places in Nice" is a claim we would
    // otherwise write into the world model as fact.
    expect(fn () => new OsmAdapter()->search(stockholmBox()))
        ->toThrow(RuntimeException::class, 'Overpass returned nothing');

    // 4 quadrants × (1 + 4 + 16 + 64) at MAX_SPLIT_DEPTH = 3.
    Http::assertSentCount(4 * (1 + 4 + 16 + 64));
});

it('keeps the region when only some boxes fail', function () {
    $calls = 0;

    Http::fake(function () use (&$calls) {
        $calls++;

        // One whole quadrant is dead at every depth; the other three answer.
        return $calls === 1 || $calls > 4
            ? Http::response('Gateway Timeout', 504)
            : Http::response(['elements' => [overpassElement($calls)]], 200);
    });

    // Three quadrants' worth of places is a usable region (conventions/09);
    // demanding all four would throw away the 3 we have.
    expect(new OsmAdapter()->search(stockholmBox()))->toHaveCount(3);
});

it('fails over to another endpoint when one is unreachable', function () {
    $hosts = [];

    Http::fake(function ($request) use (&$hosts) {
        $hosts[] = parse_url($request->url(), PHP_URL_HOST);

        // lz4 refuses the connection — which is exactly what it does from the
        // local Docker network, in 18 ms, while the main instance answers fine.
        if (str_contains($request->url(), 'lz4')) {
            throw new ConnectionException('cURL error 7: Failed to connect');
        }

        return Http::response(['elements' => [overpassElement(count($hosts))]], 200);
    });

    // All four quadrants still come back — from the second endpoint.
    expect(new OsmAdapter()->search(stockholmBox()))->toHaveCount(4)
        ->and($hosts)->toContain('overpass-api.de');
});

it('does not split when it cannot reach the server — a smaller question cannot help', function () {
    $calls = 0;

    Http::fake(function () use (&$calls) {
        $calls++;

        throw new ConnectionException('cURL error 7: Failed to connect');
    });

    expect(fn () => new OsmAdapter()->search(stockholmBox()))
        ->toThrow(RuntimeException::class, 'Overpass returned nothing');

    // 4 quadrants × 3 endpoints, and NO subdivision. The old code quartered every
    // unreachable box: Nice asked a host that was refusing us 188 times in 8
    // minutes. Splitting is the remedy for "too big", not for "can't connect".
    expect($calls)->toBe(4 * 3);
});

it('stops when the wall clock runs out, and says what it dropped', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-12 16:00:00'));

    $calls = 0;

    // Slow enough that exactly three of the four top-level quadrants fit in the
    // budget. Derived from the constant, so tuning the budget cannot quietly turn
    // this test into one that asserts nothing.
    $secondsPerBox = intdiv(OsmAdapter::BUDGET_SECONDS, 3) + 10;

    Http::fake(function () use (&$calls, $secondsPerBox) {
        $calls++;

        CarbonImmutable::setTestNow(CarbonImmutable::getTestNow()->addSeconds($secondsPerBox));

        return Http::response(['elements' => [overpassElement($calls)]], 200);
    });

    Log::spy();

    $elements = new OsmAdapter()->search(stockholmBox());

    // THE FIX. Three boxes fit; the fourth would have run past the budget — and on
    // the real thing past the job's timeout, which kills the worker mid-curl and
    // throws away everything already fetched, unwritten. So it is never started,
    // and we keep the three we have.
    expect($elements)->toHaveCount(3)
        ->and($calls)->toBe(3);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'ran out of time') && $context['boxes_skipped'] === 1);

    CarbonImmutable::setTestNow();
});

it('lets a bug in the adapter surface instead of answering it with more HTTP', function () {
    $calls = 0;

    Http::fake(function () use (&$calls) {
        $calls++;

        throw new LogicException('a real bug, not a bad day at Overpass');
    });

    // The catch used to be `Throwable`, so ANY error — including the framework's
    // own TimeoutExceededException, the signal that the job is out of time — was
    // answered by recursing into more requests. A programming error must not look
    // like "Overpass is having a bad day".
    expect(fn () => new OsmAdapter()->search(stockholmBox()))
        ->toThrow(LogicException::class, 'a real bug');

    expect($calls)->toBe(1);   // it surfaced on the first box; it did not fan out
});
