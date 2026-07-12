<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The decision trace answers for what was NOT served (PRD §15.1)
|--------------------------------------------------------------------------
|
| The reachability gate computed its exclusions and the pipeline threw them
| away, so a trace could say why an item ranked where it did but never why a
| place was not offered at all — which is the question a user actually asks.
|
*/

/**
 * Place a viewpoint $metres north of the Liljeholmen origin.
 *
 * The gate only sees candidates that coverage already let through, so the
 * "unreachable" cases have to sit INSIDE the coverage disc and still blow the
 * budget once dwell and the return leg are counted. On a 45-minute walk that
 * window is roughly 870–960 m out.
 */
function funnelPlaceAt(string $name, int $metres): void
{
    funnelPlace($name, 59.3103 + $metres / 111_320, 18.0227);
}

function funnelPlace(string $name, float $lat, float $lng): void
{
    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c;

    $place = Place::factory()->create([
        'name' => $name, 'type' => 'viewpoint', 'type_domain' => 'nature_landscape',
        'facets' => ['scenic'], 'h3_index' => $cell, 'source_tags' => ['osm' => []],
    ]);

    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [$lng, $lat, $place->id],
    );
}

function funnelPlan(int $budgetMinutes): array
{
    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => $budgetMinutes,
    ]);

    return [app(RankSession::class)->plan(ExploreSessionData::fromModel($session)), $session];
}

it('records the candidates the reachability gate dropped, and why', function () {
    funnelPlaceAt('Right here', 100);
    funnelPlaceAt('Across town', 920);   // inside coverage, over budget once you must walk back

    [$plan] = funnelPlan(budgetMinutes: 45);

    expect($plan['unreachable']['count'])->toBeGreaterThan(0);

    $names = array_column($plan['unreachable']['sample'], 'name');
    expect($names)->toContain('Across town')
        ->and($names)->not->toContain('Right here');

    // The breakdown, not just the verdict: a trace has to be able to answer
    // "how far over budget was it".
    $dropped = collect($plan['unreachable']['sample'])->firstWhere('name', 'Across town');
    expect($dropped['reachability'])->toHaveKeys(['travel_min', 'dwell_min', 'return_min', 'remaining_min'])
        ->and($dropped['reachability']['reachable'])->toBeFalse();
});

it('persists the funnel on the served trace, so the replayer can answer for it', function () {
    funnelPlaceAt('Right here', 100);
    funnelPlaceAt('Across town', 920);

    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 45,
    ]);

    app(RankSession::class)->feedFor(ExploreSessionData::fromModel($session));

    $recommendation = Recommendation::query()->where('explore_session_id', $session->id)->firstOrFail();
    $funnel = $recommendation->score_inputs['funnel'];

    expect($funnel)->toHaveKeys(['unreachable', 'held'])
        ->and($funnel['unreachable']['count'])->toBeGreaterThan(0)
        ->and(array_column($funnel['unreachable']['sample'], 'name'))->toContain('Across town');
});

it('caps the unreachable sample so a wide disc cannot bloat every trace', function () {
    // 30 unreachable candidates; the trace keeps a readable sample and an
    // honest total, rather than 30 full rows on every recommendation.
    for ($i = 0; $i < 30; $i++) {
        funnelPlaceAt("Far {$i}", 890 + $i * 2);
    }
    funnelPlaceAt('Right here', 100);

    [$plan] = funnelPlan(budgetMinutes: 45);

    expect($plan['unreachable']['count'])->toBe(30)
        ->and($plan['unreachable']['sample'])->toHaveCount(25);
});
