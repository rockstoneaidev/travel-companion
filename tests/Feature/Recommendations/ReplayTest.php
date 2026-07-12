<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Recommendations\Data\ScoringModel;
use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E10 — the replayer: deterministic under a fixed clock, sensitive to
| constants (PRD §15.2, SCORING §9.1)
|--------------------------------------------------------------------------
*/

function replaySession(): ExploreSessionData
{
    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->at(59.3250, 18.0700)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 180,
        // Pinned so the replay clock below sits inside the session's budget.
        'started_at' => CarbonImmutable::parse('2026-07-12 10:45:00', 'Europe/Stockholm'),
    ]);

    foreach ([
        ['Storkyrkan', 'church', 'religious_sacred', ['history', 'architecture'], 59.3251, 18.0705],
        ['Chokladkoppen', 'cafe', 'food_drink', ['food_drink'], 59.3248, 18.0712],
        ['Utsikten', 'viewpoint', 'nature_landscape', ['scenic'], 59.3260, 18.0690],
    ] as [$name, $type, $domain, $facets, $lat, $lng]) {
        $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c;
        $place = Place::factory()->create(['name' => $name, 'type' => $type, 'type_domain' => $domain, 'facets' => $facets, 'h3_index' => $cell]);
        DB::statement('UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [$lng, $lat, $place->id]);
    }

    return ExploreSessionData::fromModel($session);
}

it('replays deterministically under a fixed clock', function () {
    $data = replaySession();
    $at = CarbonImmutable::parse('2026-07-12 11:00:00', 'Europe/Stockholm');

    $rank = app(RankSession::class);
    $first = $rank->plan($data, $at);
    $second = $rank->plan($data, $at);

    $shape = fn (array $plan): array => array_map(
        fn (array $c): array => [$c['place_id'], $c['composite']],
        $plan['picked'],
    );

    expect($shape($first))->toBe($shape($second))
        ->and($first['picked'])->not->toBeEmpty();
});

it('shows a diff when a scoring constant changes (a new version, never an edit)', function () {
    $data = replaySession();
    $at = CarbonImmutable::parse('2026-07-12 11:00:00', 'Europe/Stockholm');
    $rank = app(RankSession::class);

    $v1 = $rank->plan($data, $at);

    // A hypothetical v2: uniqueness weighted to zero, everything into fit.
    // Built via reflection-free construction — same seam per-user overrides use.
    $v2Model = ScoringModel::v1();
    $mutated = (new ReflectionClass(ScoringModel::class))->newInstanceWithoutConstructor();
    $props = get_object_vars($v2Model);
    $props['weights']['cold']['radius'] = ['personal_fit' => .40, 'uniqueness' => .0, 'temporal_urgency' => .29, 'novelty' => .07, 'confidence' => .24];
    $ref = new ReflectionClass(ScoringModel::class);
    $ctor = $ref->getConstructor();
    $ctor->setAccessible(true);
    $ctor->invoke($mutated, 'v2-test', $props['weights'], $props['penaltyWeights'], $props['friction'], $props['urgency'], $props['novelty'], $props['uniqueness'], $props['confidence'], $props['routeFit'], $props['alpha'], $props['feed'], $props['decide']);

    $v2 = $rank->plan($data, $at, $mutated);

    $composites = fn (array $plan): array => array_column($plan['picked'], 'composite');

    expect($composites($v1))->not->toBe($composites($v2))
        ->and($v2['model']->version)->toBe('v2-test');
});

it('persists resolver_version and honest cost fields on the served trace', function () {
    // The session's started_at is pinned (10:45), and feedFor() reads the REAL
    // clock — so without freezing time this test quietly depends on what hour of
    // the day CI runs at. It passed all morning and failed after 13:45, when more
    // than the session's 180-minute budget had "elapsed" and nothing was reachable
    // any more. Freeze the clock inside the budget; the reachability gate's
    // behaviour under a depleted budget is ReachabilityGateTest's job, not this
    // test's accident.
    $this->travelTo(CarbonImmutable::parse('2026-07-12 11:00:00', 'Europe/Stockholm'));

    $data = replaySession();

    $recommendations = app(RankSession::class)->feedFor($data);

    expect($recommendations)->not->toBeEmpty()
        ->and($recommendations[0]->resolver_version)->toBe(config('resolver.version'))
        ->and($recommendations[0]->cost)->toHaveKeys(['api_calls', 'llm_tokens', 'rank_ms', 'scout_tiles_filled', 'scout_tiles_hit'])
        ->and($recommendations[0]->cost['api_calls'])->toBe(0);
});
