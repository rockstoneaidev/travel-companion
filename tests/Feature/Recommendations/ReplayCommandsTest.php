<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Recommendations\Services\CostMeter;
use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The replayer, end to end (PRD §15.2)
|--------------------------------------------------------------------------
|
| The three replay commands existed but nothing exercised them, and
| tests/Fixtures/GoldTraces was empty — so "the replayer works" was a claim,
| not a fact. These drive the actual commands.
|
*/

/** Relative to the project root — the replay commands resolve --dir with base_path(). */
function goldTraceDir(): string
{
    return 'tests/Fixtures/TmpGoldTraces';
}

function goldTracePath(): string
{
    return base_path(goldTraceDir());
}

function seedReplayPlace(string $name, float $lat, float $lng, string $type, string $domain): void
{
    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c;

    $place = Place::factory()->create([
        'name' => $name, 'type' => $type, 'type_domain' => $domain,
        'facets' => ['scenic'], 'h3_index' => $cell,
        'source_tags' => ['osm' => [], 'wikidata' => []],
    ]);

    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [$lng, $lat, $place->id],
    );
}

function servedSession(): ExploreSession
{
    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 180,
    ]);

    // Serve it once, so there is a trace to replay against.
    app(RankSession::class)->feedFor(ExploreSessionData::fromModel($session));

    return $session;
}

beforeEach(function () {
    seedReplayPlace('Trekanten', 59.3117, 18.0206, 'lake', 'nature_landscape');
    seedReplayPlace('Färgfabriken', 59.3120, 18.0190, 'gallery', 'arts_culture');
    array_map('unlink', glob(goldTracePath().'/*.json') ?: []);
});

it('records a gold trace and replays it with no drift', function () {
    $session = servedSession();

    $this->artisan('replay:record', ['session' => $session->id, '--dir' => goldTraceDir()])
        ->assertSuccessful();

    expect(glob(goldTracePath().'/*.json'))->toHaveCount(1);

    $this->artisan('replay:gold', ['--dir' => goldTraceDir()])
        ->expectsOutputToContain('serve unchanged')
        ->assertSuccessful();
});

it('replays a session and reports an identical serve', function () {
    $session = servedSession();

    $this->artisan('replay:session', ['session' => $session->id])
        ->expectsOutputToContain('identical serve')
        ->assertSuccessful();
});

it('ranks on the clock it stores, so a replay runs on the same instant', function () {
    // The bug CI found and local runs did not:
    //
    //   serve   → clock taken at MICROSECOND precision
    //   persist → served_at is a timestamp(0); the database truncates it
    //   replay  → reads served_at back, and runs on a clock up to a second EARLIER
    //
    // temporal_urgency is a function of that clock, so the replay produced a
    // different composite than the serve it was replaying — measured at ~7% of
    // instants. The replayer exists to answer "did my change alter what we serve"
    // (PRD §15.2), and it was answering "yes" one time in fourteen for a pipeline
    // that had not changed at all. A tool that lies at that rate is worse than no
    // tool, because people believe it.
    //
    // Asserting on the composite would only fail 7% of the time, and asserting
    // served_at->micro === 0 proves nothing (the column truncates either way). The
    // honest invariant is that the SLACK we recorded is the slack a replay
    // recomputes — freeze a clock with a fat sub-second part and compare.
    $this->travelTo(CarbonImmutable::parse('2026-07-12 11:00:00.831742', 'Europe/Stockholm'));

    $session = servedSession();
    $data = ExploreSessionData::fromModel($session);

    $recommendation = Recommendation::query()
        ->where('explore_session_id', $session->id)
        ->orderBy('position')
        ->firstOrFail();
    $servedSlack = $recommendation->score_inputs['raw']['temporal_urgency']['slack_min'];

    // Replay exactly as ReplaySessionCommand does: from the stored clock.
    $replayed = app(RankSession::class)->plan($data, $recommendation->served_at->toImmutable());
    $replayedSlack = $replayed['picked'][0]['raw_inputs']['temporal_urgency']['slack_min'];

    expect($replayedSlack)->toBe($servedSlack);
});

it('shows the pipeline funnel, not just what was served', function () {
    $session = servedSession();

    // The funnel is the whole point: a candidate killed at a gate leaves no
    // mark on the served list, so a served-only diff can never explain it.
    $this->artisan('replay:session', ['session' => $session->id])
        ->expectsOutputToContain('scouted')
        ->expectsOutputToContain('held at Decide')
        ->expectsOutputToContain('served')
        ->assertSuccessful();
});

it('refuses to replay a session that never served anything', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id,
    ]);

    $this->artisan('replay:session', ['session' => $session->id])->assertFailed();
});

it('measures cost rather than asserting it', function () {
    // E16 put a real edge call on the serve path (weather). This test caught it the
    // moment it landed, which is exactly what it was written to do — so now it
    // asserts the true number instead of a comfortable zero.
    Http::fake(['api.open-meteo.com/*' => Http::response(['current' => ['temperature_2m' => 19.0, 'precipitation' => 0.0, 'weather_code' => 0, 'cloud_cover' => 10]])]);

    $session = servedSession();

    $recommendation = Recommendation::query()->where('explore_session_id', $session->id)->firstOrFail();

    // One weather call per SESSION, not per candidate: it is cached per tile, and
    // everyone in the hex is under the same sky (conventions/12). If this ever
    // reads as the candidate count, the shared-tile cache has been broken.
    expect($recommendation->cost['api_calls'])->toBe(1)
        ->and($recommendation->cost['api_calls_by_host'])->toBe(['api.open-meteo.com' => 1])
        ->and($recommendation->cost['llm_tokens'])->toBe(0);

    // Prove the meter actually counts, rather than being a dressed-up literal.
    // Cleared first: the serve above legitimately left the weather call on it.
    $meter = app(CostMeter::class);
    $meter->reset();
    $meter->recordApiCall('places.googleapis.com');
    $meter->recordLlmTokens(1_200);

    expect($meter->apiCalls())->toBe(1)
        ->and($meter->llmTokens())->toBe(1_200)
        ->and($meter->byHost())->toBe(['places.googleapis.com' => 1]);
});

afterEach(function () {
    array_map('unlink', glob(goldTracePath().'/*.json') ?: []);

    if (is_dir(goldTracePath())) {
        rmdir(goldTracePath());
    }
});

it('will not recommend a place it can verify is shut', function () {
    config()->set('services.google.maps_key', 'test-key');

    // A distinct Google id per place, as Google would give — otherwise the
    // collision guard (rightly) refuses to verify the second one.
    $n = 0;
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => function () use (&$n) {
            $n++;

            return Http::response(['places' => [['id' => "ChIJclosed{$n}"]]]);
        },
        'places.googleapis.com/v1/places/*' => Http::response(['currentOpeningHours' => ['openNow' => false]]),
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-07-12 11:00:00', 'Europe/Stockholm'));

    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 180,
    ]);

    $recommendations = app(RankSession::class)->feedFor(ExploreSessionData::fromModel($session));

    // "We do not tell a user a place is open on the strength of a day-old cache"
    // (conventions/12). Verified shut, at serve time, means it is not served — and
    // a shorter honest feed beats a longer one that sends someone to a locked door.
    expect($recommendations)->toBeEmpty();
});
