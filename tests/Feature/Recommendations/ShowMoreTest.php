<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Recommendations\Enums\ServeReason;
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
| "Show more" — the next menu's worth of best places
|--------------------------------------------------------------------------
|
| The middle ground between the ten-card feed and the exhaustive browse list: same ranking,
| more of it, as full cards, without losing the ones already on screen.
|
*/

function moreSession(int $placeCount = 40): ExploreSession
{
    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);

    for ($i = 0; $i < $placeCount; $i++) {
        $p = Place::factory()->create([
            'h3_index' => DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [18.0227 + ($i % 8) * 0.001, 59.3103 + intdiv($i, 8) * 0.001])->c,
            'source_tags' => ['osm' => [], 'wikidata' => []],
        ]);
        DB::statement('UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
            [18.0227 + ($i % 8) * 0.001, 59.3103 + intdiv($i, 8) * 0.001, $p->id]);
    }

    return ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 240,
    ]);
}

it('serves a menu of ten', function () {
    $session = moreSession();

    $feed = app(RankSession::class)->feedFor(ExploreSessionData::fromModel($session));

    expect($feed)->toHaveCount(10);
});

it('appends the next best cards to the same batch, without repeating one', function () {
    $session = moreSession();
    $data = ExploreSessionData::fromModel($session);
    $rank = app(RankSession::class);

    $first = $rank->feedFor($data);
    $firstIds = array_map(static fn ($r): string => $r->score_inputs['candidate']['place_id'], $first);
    $group = $first[0]->serve_group;

    $extended = $rank->serveMore($data);

    // The batch grew — the first ten are still there, plus more, all in ONE group.
    expect(count($extended))->toBeGreaterThan(10)
        ->and(collect($extended)->pluck('serve_group')->unique()->all())->toBe([$group]);

    // The appended cards are the NEXT best — real recommendations (ServeReason::More), and
    // none of them repeats a place already on screen.
    $extendedIds = array_map(static fn ($r): string => $r->score_inputs['candidate']['place_id'], $extended);
    expect(count($extendedIds))->toBe(count(array_unique($extendedIds)));

    $appended = array_values(array_diff($extendedIds, $firstIds));
    expect($appended)->not->toBeEmpty();

    expect(Recommendation::query()->where('serve_reason', ServeReason::More->value)->count())->toBeGreaterThan(0);
});

it('appends cards, not stripped browse rows — they carry a full trace', function () {
    $session = moreSession();
    $data = ExploreSessionData::fromModel($session);
    $rank = app(RankSession::class);

    $rank->feedFor($data);
    $rank->serveMore($data);

    // Every appended card is a real recommendation with an opportunity and a composite — the
    // thing that makes "why did I get this", keep and dismiss all work, exactly as the first
    // ten do. (This is the whole difference from the free browse list.)
    $more = Recommendation::query()->where('serve_reason', ServeReason::More->value)->get();
    expect($more)->not->toBeEmpty();
    foreach ($more as $r) {
        expect($r->opportunity_id)->not->toBeNull()
            ->and($r->scores)->toHaveKey('composite');
    }
});

it('stops at the per-session serve ceiling, however many times the button is pressed', function () {
    config()->set('trips.reanchor.max_serves_per_session', 3);

    $session = moreSession(80);
    $data = ExploreSessionData::fromModel($session);
    $rank = app(RankSession::class);

    $rank->feedFor($data);   // serve 1

    // Press "show more" far more times than the ceiling allows.
    for ($i = 0; $i < 10; $i++) {
        $rank->serveMore($data);
    }

    // A button is what an accidental loop leans on. The ceiling is a property of the session,
    // not of the reason, so it holds here exactly as it does for the refresh.
    $serves = Recommendation::query()->where('explore_session_id', $session->id)->distinct()->count('served_at');
    expect($serves)->toBeLessThanOrEqual(3);
});
