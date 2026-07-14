<?php

declare(strict_types=1);

use App\Domain\Places\Enums\PlaceType;
use App\Domain\Places\Models\Place;
use App\Domain\Places\Services\CoverageGeometry;
use App\Domain\Places\Services\ScoutRunner;
use App\Domain\Places\Services\Scouts\NearbyPlaceScout;
use App\Domain\Places\Services\Scouts\RouteDetourScout;
use App\Domain\Trips\Enums\TravelMode;
use App\Jobs\Scouts\WarmTileJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E35 — the corridor is a sequence, not a bag
|--------------------------------------------------------------------------
|
| The thing that makes route scouting affordable is ORDER. An unordered corridor of
| four thousand cells can only be scouted in full or not at all, and both answers are
| wrong. Ordered by progress along the route, it becomes a prefix you can afford now
| and a tail a queue can chew on while the car drives toward it.
|
| Every test below is really the same test: does the code know which way the traveller
| is going?
|
*/

/** Lay a line of places along a route, so the res-6 prefilter doesn't eat the corridor. */
function placesAlong(float $fromLat, float $fromLng, float $toLat, float $toLng, int $count): void
{
    for ($i = 0; $i <= $count; $i++) {
        $f = $i / $count;

        placeAt(
            $fromLat + ($toLat - $fromLat) * $f,
            $fromLng + ($toLng - $fromLng) * $f,
            "Waypoint {$i}",
            PlaceType::Viewpoint,
        );
    }
}

function placeAt(float $lat, float $lng, string $name, PlaceType $type): Place
{
    return Place::factory()->create([
        'name' => $name,
        'type' => $type,
        'type_domain' => $type->domain(),
        'location' => DB::raw(sprintf("ST_GeogFromText('POINT(%F %F)')", $lng, $lat)),
        'h3_index' => DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c,
    ]);
}

// Stockholm → Södertälje, roughly: far enough to be a corridor, short enough to be a test.
const FROM_LAT = 59.3293;
const FROM_LNG = 18.0686;
const TO_LAT = 59.1955;
const TO_LNG = 17.6253;

it('orders corridor tiles by progress along the route, so the budget takes the road ahead and not a random slice of it', function () {
    placesAlong(FROM_LAT, FROM_LNG, TO_LAT, TO_LNG, 60);

    $coverage = app(CoverageGeometry::class)->forSession(
        FROM_LAT, FROM_LNG, TravelMode::Drive, 120,
        destLat: TO_LAT, destLng: TO_LNG,
    );

    $tiles = $coverage->allTiles();
    expect($tiles)->not->toBeEmpty();

    /*
     * The claim: the tiles we scout inline are the ones NEAR THE START of the route.
     * If the ordering were lost, the budget would slice an arbitrary chunk out of the
     * middle — the feed would be full of places forty minutes down the E20 and empty
     * of the ones you are about to drive past, which is precisely backwards.
     *
     * So: the mean distance from the origin to an inline tile must be smaller than the
     * mean distance to a pending one. (Not "every inline tile is closer than every
     * pending one" — the corridor has width, and cells abreast of each other sort
     * together by design.)
     */
    $meanDistance = function (array $cells): float {
        if ($cells === []) {
            return INF;
        }

        return (float) DB::selectOne(
            'SELECT AVG(ST_Distance(
                        h3_cell_to_geometry(c::h3index)::geography,
                        ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
                    )) AS d
             FROM unnest(?::text[]) AS c',
            [FROM_LNG, FROM_LAT, '{'.implode(',', $cells).'}'],
        )->d;
    };

    if ($coverage->pendingTiles !== []) {
        expect($meanDistance($coverage->allTiles()))
            ->toBeLessThan($meanDistance($coverage->pendingTiles));
    }
});

it('never scouts more tiles inline than the budget allows, however long the drive is', function () {
    placesAlong(FROM_LAT, FROM_LNG, TO_LAT, TO_LNG, 120);

    config()->set('tiles.coverage.max_inline_tiles', 12);

    $coverage = app(CoverageGeometry::class)->forSession(
        FROM_LAT, FROM_LNG, TravelMode::Drive, 240,
        destLat: TO_LAT, destLng: TO_LNG,
    );

    /*
     * The far set is what the corridor contributes, and it is what the budget caps. The
     * near ring is added on top of it — a walking-scale disc around origin and
     * destination, bounded by its own radius, not by this budget.
     *
     * This is the test that stands between us and a request that warms four thousand
     * tiles because somebody typed "Göteborg" into a destination field.
     */
    expect(count($coverage->farTiles))->toBeLessThanOrEqual(12);
});

it('hands the road ahead to the queue, and does not let it into the feed', function () {
    Queue::fake();
    placesAlong(FROM_LAT, FROM_LNG, TO_LAT, TO_LNG, 80);

    config()->set('tiles.coverage.max_inline_tiles', 8);

    $coverage = app(CoverageGeometry::class)->forSession(
        FROM_LAT, FROM_LNG, TravelMode::Drive, 240,
        destLat: TO_LAT, destLng: TO_LNG,
    );

    expect($coverage->pendingTiles)->not->toBeEmpty();

    // The pending tiles are NOT readable. `allTiles()` is what the ranker consumes, and
    // a tile nobody has warmed yet must not be in it — otherwise the same rank, run
    // twice, returns different cards depending on how far a worker happened to have got.
    expect(array_intersect($coverage->pendingTiles, $coverage->allTiles()))->toBeEmpty();

    app(ScoutRunner::class)->warm($coverage);

    // ...but they ARE dispatched, so they will be warm by the time the car arrives.
    Queue::assertPushed(WarmTileJob::class, fn (WarmTileJob $job): bool => in_array($job->h3Index, $coverage->pendingTiles, true)
        && $job->scoutClass === RouteDetourScout::class);
});

it('does not pre-scout near-range sources up the motorway', function () {
    Queue::fake();
    placesAlong(FROM_LAT, FROM_LNG, TO_LAT, TO_LNG, 80);

    config()->set('tiles.coverage.max_inline_tiles', 8);

    $coverage = app(CoverageGeometry::class)->forSession(
        FROM_LAT, FROM_LNG, TravelMode::Drive, 240,
        destLat: TO_LAT, destLng: TO_LNG,
    );

    app(ScoutRunner::class)->warm($coverage);

    // The payoff gradient (conventions/12), enforced where it costs money. A café 40 km
    // up the E4 is noise, and pre-scouting noise costs exactly what pre-scouting signal
    // costs.
    Queue::assertNotPushed(WarmTileJob::class, fn (WarmTileJob $job): bool => $job->scoutClass === NearbyPlaceScout::class);
});

it('offers reasons to pull over, not corner shops', function () {
    // Two places in one tile: one worth an exit, one emphatically not.
    $cliff = placeAt(FROM_LAT, FROM_LNG, 'Cliff over the lake', PlaceType::Viewpoint);
    placeAt(FROM_LAT, FROM_LNG, 'Petrol station kiosk', PlaceType::SpecialtyShop);

    $cell = $cliff->h3_index;

    $names = array_column(app(RouteDetourScout::class)->candidatesForTile($cell), 'name');

    // Nobody leaves a motorway for a kiosk. The corridor's candidate set has to know that,
    // because `route_fit` can only price a detour — it cannot tell you the detour was
    // never worth considering.
    expect($names)->toContain('Cliff over the lake')
        ->and($names)->not->toContain('Petrol station kiosk');
});
