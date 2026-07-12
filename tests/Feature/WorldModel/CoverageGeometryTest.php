<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Places\Services\CoverageGeometry;
use App\Domain\Trips\Enums\TravelMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Coverage geometry: disc · cone · corridor (E5, PRD §9.3)
|--------------------------------------------------------------------------
|
| The cone's ±60° half-angle and its "behind" tail were configured but never
| asserted, and the corridor branch — which is reachable in production the
| moment a session has a destination — had no test at all.
|
*/

const ORIGIN_LAT = 59.3103;

const ORIGIN_LNG = 18.0227;

/** A place $metres away on $bearing degrees (0 = north, 90 = east). */
function placeOnBearing(string $name, float $bearingDeg, int $metres): string
{
    $rad = deg2rad($bearingDeg);
    $lat = ORIGIN_LAT + ($metres * cos($rad)) / 111_320;
    $lng = ORIGIN_LNG + ($metres * sin($rad)) / (111_320 * cos(deg2rad(ORIGIN_LAT)));

    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c;

    $place = Place::factory()->create([
        'name' => $name, 'type' => 'viewpoint', 'type_domain' => 'nature_landscape',
        'facets' => ['scenic'], 'h3_index' => $cell, 'source_tags' => ['osm' => []],
    ]);

    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [$lng, $lat, $place->id],
    );

    return $cell;
}

function coverage(?int $heading = null, ?float $destLat = null, ?float $destLng = null, int $budget = 60): array
{
    $geometry = app(CoverageGeometry::class);

    return $geometry->forSession(
        ORIGIN_LAT, ORIGIN_LNG, TravelMode::Walk, $budget, $heading, $destLat, $destLng,
    )->allTiles();
}

it('covers every direction when the traveler has no heading', function () {
    $north = placeOnBearing('North', 0, 900);
    $south = placeOnBearing('South', 180, 900);

    $tiles = coverage();

    expect($tiles)->toContain($north)
        ->and($tiles)->toContain($south);
});

it('keeps what is straight ahead and inside the ±60° cone', function () {
    // Heading north. 45° off-axis is inside the cone; both should survive.
    $ahead = placeOnBearing('Dead ahead', 0, 900);
    $insideCone = placeOnBearing('Just inside', 45, 900);

    $tiles = coverage(heading: 0);

    expect($tiles)->toContain($ahead)
        ->and($tiles)->toContain($insideCone);
});

it('drops what is far behind you — walking backwards is not exploring', function () {
    // A 3-hour walk reaches ~3.9 km ahead; the 0.40 tail reaches ~1.5 km behind.
    // 2.5 km due SOUTH is inside the reach, outside the 1.2 km near ring, and
    // well past the tail — so only the cone can be what excludes it.
    $behind = placeOnBearing('Behind', 180, 2500);
    $ahead = placeOnBearing('Ahead', 0, 2500);

    $tiles = coverage(heading: 0, budget: 180);

    expect($tiles)->toContain($ahead)
        ->and($tiles)->not->toContain($behind);
});

it('keeps something behind you but within the tail — a pear, not a wedge', function () {
    // 1.4 km behind: past the near ring, still inside the 0.40 tail (~1.5 km).
    // A hard wedge would make the app blind to the street you just walked down.
    $inTail = placeOnBearing('In the tail', 180, 1400);

    expect(coverage(heading: 0, budget: 180))->toContain($inTail);
});

it('follows the corridor to a destination, not a disc around the origin', function () {
    // Destination 1.5 km north. A place near the midpoint of that walk is in
    // the corridor; one the same distance from the origin but sideways is not.
    $destLat = ORIGIN_LAT + 1500 / 111_320;

    $onTheWay = placeOnBearing('On the way', 0, 800);
    // The corridor is ~800 m wide, so put this clearly outside it rather than
    // on the boundary — a tile-quantized edge case proves nothing.
    $offToTheSide = placeOnBearing('Off to the side', 90, 2000);

    $tiles = coverage(destLat: $destLat, destLng: ORIGIN_LNG, budget: 180);

    expect($tiles)->toContain($onTheWay)
        ->and($tiles)->not->toContain($offToTheSide);
});
