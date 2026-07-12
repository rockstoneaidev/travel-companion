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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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
    $session = servedSession();

    $recommendation = Recommendation::query()->where('explore_session_id', $session->id)->firstOrFail();

    // Phase 1 ranks off our own database: zero paid calls, zero LLM tokens.
    // The point is that this is now *counted*, so a paid call added to the
    // serve path would show up here instead of silently reporting zero.
    expect($recommendation->cost['api_calls'])->toBe(0)
        ->and($recommendation->cost['llm_tokens'])->toBe(0)
        ->and($recommendation->cost)->toHaveKey('api_calls_by_host');

    // Prove the meter actually counts, rather than being a dressed-up literal.
    $meter = app(CostMeter::class);
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
