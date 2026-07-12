<?php

declare(strict_types=1);

use App\Domain\Context\Data\WeatherContext;
use App\Domain\Context\Services\WeatherClient;
use App\Domain\Sources\Services\CircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| E16 — weather at the edge (PRD §9.2, conventions/12)
|--------------------------------------------------------------------------
|
| Weather is a reason to nudge, never a reason to fail. Everything here is
| about that: it is cached per TILE (not per user), it degrades to "unknown"
| rather than erroring, and "unknown" is never treated as "bad".
|
*/

function meteo(array $current): void
{
    Http::fake(['api.open-meteo.com/*' => Http::response(['current' => $current])]);
}

it('calls once per tile and serves everyone else from the cache', function () {
    meteo(['temperature_2m' => 21.0, 'precipitation' => 0.0, 'weather_code' => 0, 'cloud_cover' => 5]);

    $client = app(WeatherClient::class);

    // Three users standing in the same hex are standing under the same sky.
    $client->forTile('8808866111fffff');
    $client->forTile('8808866111fffff');
    $weather = $client->forTile('8808866111fffff');

    // A user id in this key would destroy the shared cache and multiply the bill
    // by the user count (conventions/12).
    Http::assertSentCount(1);

    expect($weather->temperatureC)->toBe(21.0)
        ->and($weather->isWet())->toBeFalse()
        ->and($weather->lightIsGood())->toBeTrue();
});

it('degrades to "unknown" when the sky is unreachable — it does not fail the feed', function () {
    Http::fake(['api.open-meteo.com/*' => Http::response('boom', 500)]);

    $weather = app(WeatherClient::class)->forTile('8808866111fffff');

    // A recommendation missing its weather is a recommendation. One that never
    // arrives is not (SCORING §2.5 — a missing signal drops out of the sum).
    expect($weather->known())->toBeFalse()
        // ...and unknown must never be read as "bad weather". Absence of evidence.
        ->and($weather->isWet())->toBeFalse()
        ->and($weather->lightIsGood())->toBeTrue();
});

it('stops asking a source that keeps failing', function () {
    Http::fake(['api.open-meteo.com/*' => Http::response('boom', 500)]);

    $client = app(WeatherClient::class);

    // Each of these is a different tile, so none of them is a cache hit.
    foreach (range(1, 8) as $i) {
        $client->forTile("880886611{$i}fffff");
    }

    // The breaker trips at 5. Users 6, 7 and 8 get their feed WITHOUT waiting out
    // a timeout to learn what user 5 already learned — the whole point of it
    // sitting on the read path.
    Http::assertSentCount(5);

    expect(app(CircuitBreaker::class)->isOpen(WeatherClient::SOURCE))->toBeTrue();
});

it('refuses to call golden hour golden under a lid of cloud', function () {
    // The sun can be at exactly the right angle and the light still be flat grey.
    $overcast = new WeatherContext(temperatureC: 14.0, precipitationMm: 0.0, weatherCode: 3, cloudCoverPercent: 95);
    $clear = new WeatherContext(temperatureC: 22.0, precipitationMm: 0.0, weatherCode: 0, cloudCoverPercent: 10);
    $raining = new WeatherContext(temperatureC: 12.0, precipitationMm: 1.4, weatherCode: 61, cloudCoverPercent: 100);

    // "The light is good right now" is a factual claim, and we do not make factual
    // claims we cannot support. Geometry alone must not raise the special-moment
    // floor (SCORING §4.3).
    expect($clear->lightIsGood())->toBeTrue()
        ->and($overcast->lightIsGood())->toBeFalse()
        ->and($raining->lightIsGood())->toBeFalse()
        ->and($raining->isWet())->toBeTrue();
});

it('caches the tile, not the user', function () {
    meteo(['temperature_2m' => 18.0, 'precipitation' => 0.0, 'weather_code' => 1, 'cloud_cover' => 30]);

    app(WeatherClient::class)->forTile('8808866111fffff');

    // Saying it twice on purpose, because it is the one mistake that is invisible
    // until the bill arrives.
    expect(Cache::get('weather:8808866111fffff'))->not->toBeNull();
});

it('lets a bug surface instead of reporting it as a flaky source', function () {
    // The breaker catches Exception, not Throwable. A TypeError, a missing property,
    // any plain bug in the closure must NOT be swallowed: doing so turns "I broke
    // the code" into "the source is down", degrades every feed silently, and
    // eventually trips the breaker on a fault with nothing to do with the network.
    // This is not hypothetical — it hid a fatal in this very file for twenty minutes.
    $breaker = app(CircuitBreaker::class);

    expect(fn () => $breaker->call('some-source', fn () => throw new Error('a real bug'), fallback: null))
        ->toThrow(Error::class, 'a real bug');

    // ...and a bug does not count against the source's failure budget.
    expect($breaker->isOpen('some-source'))->toBeFalse();
});

/*
| The privacy regression guard (PROCESSORS.md §5, ROPA B1/B3).
|
| This bug shipped once and was invisible for exactly one reason: the CACHE KEY was
| the tile, so the code read as tile-shaped, while the REQUEST BODY carried the
| session origin — a person's actual position — to a third party we hold no Art. 28
| DPA with. The cache key is not the thing that leaves the machine. This asserts the
| thing that leaves the machine.
*/
it('sends the tile centroid to Open-Meteo, never a real position', function () {
    Cache::flush();

    // A user standing at a precise, distinctive spot inside the hex.
    $userLat = 59.334591;
    $userLng = 18.063240;
    $cell = DB::selectOne(
        'SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS cell',
        [$userLng, $userLat],
    )->cell;

    Http::fake([
        'api.open-meteo.com/*' => Http::response(['current' => ['temperature_2m' => 17.0]]),
    ]);

    app(WeatherClient::class)->forTile($cell);

    Http::assertSent(function ($request) use ($userLat, $userLng, $cell): bool {
        $sentLat = (float) $request->data()['latitude'];
        $sentLng = (float) $request->data()['longitude'];

        // Not the person. The hex centre is metres-to-hundreds-of-metres away, so an
        // exact match would mean the origin leaked straight through.
        expect($sentLat)->not->toBe($userLat)
            ->and($sentLng)->not->toBe($userLng);

        // And it is genuinely this hex's centre — not some other tile, not (0,0).
        $centre = DB::selectOne(
            'SELECT ST_Y(g) AS lat, ST_X(g) AS lng FROM (SELECT h3_cell_to_geometry(?::h3index) AS g) t',
            [$cell],
        );

        return abs($sentLat - (float) $centre->lat) < 0.000001
            && abs($sentLng - (float) $centre->lng) < 0.000001;
    });
});
