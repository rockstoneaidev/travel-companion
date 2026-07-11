<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Places\Models\ScoutRun;
use App\Domain\Places\Services\CacheKeys;
use App\Domain\Places\Services\CoverageGeometry;
use App\Domain\Places\Services\ScoutRunner;
use App\Domain\Trips\Enums\TravelMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E5 — coverage geometry, shared tile cache, scout runner
|--------------------------------------------------------------------------
*/

function seedPlace(float $lat, float $lng, string $type, string $domain, string $name): void
{
    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c;
    $place = Place::factory()->create([
        'name' => $name, 'type' => $type, 'type_domain' => $domain, 'h3_index' => $cell,
    ]);
    DB::statement('UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [$lng, $lat, $place->id]);
}

it('builds a walking disc with a near/far split and drops empty countryside', function () {
    seedPlace(59.3250, 18.0700, 'church', 'religious_sacred', 'Storkyrkan');

    $coverage = app(CoverageGeometry::class)->forSession(59.3250, 18.0700, TravelMode::Walk, 120);

    // Walking 120 min → a modest disc; near ring ⊂ coverage; the res-6
    // prefilter keeps only tiles whose neighborhood holds places at all.
    expect($coverage->nearTiles)->not->toBeEmpty()
        ->and($coverage->originCell)->toStartWith('88')
        ->and(count($coverage->allTiles()))->toBeGreaterThan(5)
        ->and(count($coverage->allTiles()))->toBeLessThan(80);
});

it('narrows coverage to a cone when a heading is set', function () {
    seedPlace(59.3250, 18.0700, 'church', 'religious_sacred', 'Storkyrkan');

    $geometry = app(CoverageGeometry::class);
    $disc = $geometry->forSession(59.3250, 18.0700, TravelMode::Bike, 120);
    $cone = $geometry->forSession(59.3250, 18.0700, TravelMode::Bike, 120, headingDeg: 0);

    expect(count($cone->allTiles()))->toBeLessThan(count($disc->allTiles()));
});

it('fills once, hits on the second pass, and honors ScoutRange near/far', function () {
    seedPlace(59.3250, 18.0700, 'cafe', 'food_drink', 'Kaffestugan');
    seedPlace(59.3250, 18.0700, 'castle', 'historic_heritage', 'Slottet');

    $coverage = app(CoverageGeometry::class)->forSession(59.3250, 18.0700, TravelMode::Walk, 60);
    $runner = app(ScoutRunner::class);

    $first = collect($runner->warm($coverage))->keyBy('scout');
    $second = collect($runner->warm($coverage))->keyBy('scout');

    expect($first['nearby']['filled'])->toBeGreaterThan(0)
        ->and($first['nearby']['hits'])->toBe(0)
        ->and($second['nearby']['hit_rate'])->toBe(1.0)
        ->and($second['history']['hit_rate'])->toBe(1.0)
        // nearby is ScoutRange::Near — it must scout fewer tiles than a Full scout.
        ->and($first['nearby']['tiles'])->toBeLessThanOrEqual($first['history']['tiles'])
        ->and($first['history']['candidates'])->toBeGreaterThan(0)
        ->and(ScoutRun::query()->where('scout', 'nearby')->count())->toBe(2);
});

it('serves ranked reads from cache only and never leaks a user id into keys', function () {
    seedPlace(59.3250, 18.0700, 'viewpoint', 'nature_landscape', 'Utsikten');

    $coverage = app(CoverageGeometry::class)->forSession(59.3250, 18.0700, TravelMode::Walk, 60);
    $runner = app(ScoutRunner::class);
    $runner->warm($coverage);

    $candidates = $runner->candidates($coverage->allTiles());

    expect(collect($candidates)->pluck('name'))->toContain('Utsikten')
        // The key format is load-bearing (conventions/12): source, tile, version — nothing else.
        ->and(CacheKeys::scout('nearby', '8808866189fffff', 'v1'))->toBe('scout:nearby:8808866189fffff:v1');
});
