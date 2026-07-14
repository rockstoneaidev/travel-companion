<?php

declare(strict_types=1);

use App\Domain\Places\Data\Coordinates;
use App\Domain\Places\Models\Place;
use App\Domain\Recommendations\Enums\ServeReason;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E46 — the living feed
|--------------------------------------------------------------------------
|
| Two things the founder walked into, testing in Stockholm on 2026-07-14:
|
|   1. Walking Liljeholmen → Hornstull and pulling showed the SAME feed. The
|      session was ranked once, at its origin, and every later read replayed those
|      rows. The backend was not ignoring the movement — the client never told it.
|   2. Dismissing a card left a hole that never filled.
|
| Neither is Phase 2 machinery. Both are PRD §8.1's own loop ("re-opening the app
| yields a fresh menu, scored against the remaining budget") never having been
| wired. These tests are that loop.
|
*/

/** Liljeholmen — where the session starts. */
const LILJEHOLMEN = ['lat' => 59.3103, 'lng' => 18.0227];

/** Hornstull — ~1.5 km north-east, out of reach on a 45-minute walking budget. */
const HORNSTULL = ['lat' => 59.3155, 'lng' => 18.0345];

function feedPlace(string $name, array $at): Place
{
    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$at['lng'], $at['lat']])->c;

    $place = Place::factory()->create([
        'name' => $name, 'type' => 'viewpoint', 'type_domain' => 'nature_landscape',
        'facets' => ['scenic'], 'h3_index' => $cell, 'source_tags' => ['osm' => []],
    ]);

    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [$at['lng'], $at['lat'], $place->id],
    );

    return $place;
}

/** A live session anchored in Liljeholmen, already served once. */
function livingSession(User $user, int $budget = 45): ExploreSession
{
    $trip = Trip::factory()->create(['user_id' => $user->id]);

    $session = ExploreSession::factory()->at(LILJEHOLMEN['lat'], LILJEHOLMEN['lng'])->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => $budget,
    ]);

    app(RankSession::class)->feedFor(ExploreSessionData::fromModel($session));

    return $session;
}

/** What the client now does at pull time, and never used to: say where it is. */
function reportPosition(ExploreSession $session, array $at, int $accuracy = 20): void
{
    test()->postJson("/explore/{$session->id}/context-events", [
        'location' => ['lat' => $at['lat'], 'lng' => $at['lng'], 'accuracy_m' => $accuracy],
        'app_state' => 'foreground',
    ])->assertNoContent();
}

function servedNames(ExploreSession $session, ?int $group = null): array
{
    return Recommendation::query()
        ->where('explore_session_id', $session->id)
        ->when($group !== null, fn ($q) => $q->where('serve_group', $group))
        ->orderBy('position')
        ->get()
        ->map(fn (Recommendation $r): string => $r->score_inputs['candidate']['name'])
        ->all();
}

it('re-anchors the feed when the user walks to a new neighbourhood', function () {
    feedPlace('Vinterviken', LILJEHOLMEN);
    feedPlace('Tantolunden', HORNSTULL);

    $this->actingAs($user = profilingConsent(User::factory()->create()));
    $session = livingSession($user);

    // The original serve: Liljeholmen only. Hornstull is out of reach from here on a
    // 45-minute walking budget, which is the point — it CANNOT be a coincidence.
    expect(servedNames($session, 1))->toBe(['Vinterviken']);

    // Walk. (Past the re-serve interval — a feed that re-ranks every few seconds is
    // churn, not responsiveness.)
    $this->travelTo(now()->addMinutes(5));
    reportPosition($session, HORNSTULL);

    // ...and pull. This is the GET the browser makes; it used to replay batch 1 forever.
    $this->get("/explore/{$session->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('opportunities.data.0.title', 'Tantolunden')
            ->where('serve.group', 2)
            ->where('serve.reason', 'move_reanchor'));

    // Batch 1 is untouched — it is the record of what we served in Liljeholmen, and
    // walking away from a place does not unsay it (PRD §15.1).
    expect(servedNames($session, 1))->toBe(['Vinterviken'])
        ->and(servedNames($session, 2))->toBe(['Tantolunden']);

    // And it recorded WHERE it ranked from, which is not where the session started.
    $batch2 = Recommendation::query()->where('explore_session_id', $session->id)->where('serve_group', 2)->firstOrFail();
    expect($batch2->anchor->lat)->toEqualWithDelta(HORNSTULL['lat'], 0.0001)
        ->and($batch2->serve_reason)->toBe(ServeReason::MoveReanchor)
        // The session's own origin still means "where this session began".
        ->and($session->fresh()->origin->lat)->toEqualWithDelta(LILJEHOLMEN['lat'], 0.0001);
});

it('does not re-anchor for a GPS twitch', function () {
    feedPlace('Vinterviken', LILJEHOLMEN);

    $this->actingAs($user = profilingConsent(User::factory()->create()));
    $session = livingSession($user);

    $this->travelTo(now()->addMinutes(5));

    // 80 m up the street. Below the 400 m drift threshold: the user is standing still
    // and the phone is guessing.
    reportPosition($session, ['lat' => LILJEHOLMEN['lat'] + 0.0007, 'lng' => LILJEHOLMEN['lng']]);

    $this->get("/explore/{$session->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('serve.group', 1));

    expect(Recommendation::query()->where('explore_session_id', $session->id)->max('serve_group'))->toBe(1);
});

it('does not mistake a bad fix for a walk', function () {
    feedPlace('Vinterviken', LILJEHOLMEN);
    feedPlace('Tantolunden', HORNSTULL);

    $this->actingAs($user = profilingConsent(User::factory()->create()));
    $session = livingSession($user);

    $this->travelTo(now()->addMinutes(5));

    // 1.5 km of "drift", reported by a phone that admits it could be anywhere within
    // 2 km. That is not evidence of travel — it is the error bar, and re-ranking on it
    // is how a stationary user indoors gets their feed reshuffled in circles.
    reportPosition($session, HORNSTULL, accuracy: 2000);

    $this->get("/explore/{$session->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('serve.group', 1));
});

it('tops the feed back up when a card is dismissed', function () {
    foreach (['Vinterviken', 'Skinnarviksberget', 'Ivar Los park', 'Tantolunden', 'Bergsunds strand', 'Reimersholme'] as $i => $name) {
        feedPlace($name, ['lat' => LILJEHOLMEN['lat'] + $i * 0.0005, 'lng' => LILJEHOLMEN['lng']]);
    }

    $this->actingAs($user = profilingConsent(User::factory()->create()));
    $session = livingSession($user);

    // A full menu: 5 (config trips.session.feed_size), with a 6th held in reserve.
    $first = Recommendation::query()->where('explore_session_id', $session->id)->orderBy('position')->get();
    expect($first)->toHaveCount(5);

    $rejected = $first->first();
    $rejectedPlace = $rejected->score_inputs['candidate']['place_id'];

    $this->postJson("/recommendations/{$rejected->id}/feedback", ['event' => 'dismissed'])->assertNoContent();

    // The pull after the dismissal POST lands. A card slides in — the feed does not
    // simply get shorter, which is what it did before E46.
    $this->get("/explore/{$session->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('opportunities.data', 5));

    $backfill = Recommendation::query()
        ->where('explore_session_id', $session->id)
        ->where('serve_reason', ServeReason::DismissBackfill)
        ->get();

    expect($backfill)->toHaveCount(1)
        // It joined the batch on screen rather than opening a new one — the menu was
        // topped up, not replaced.
        ->and($backfill->first()->serve_group)->toBe(1)
        ->and($backfill->first()->position)->toBe(6)
        // ...and it is emphatically not the place they just refused.
        ->and($backfill->first()->score_inputs['candidate']['place_id'])->not->toBe($rejectedPlace);
});

it('never re-offers a place the user dismissed, even in a brand-new batch', function () {
    feedPlace('Vinterviken', LILJEHOLMEN);
    feedPlace('Tantolunden', HORNSTULL);

    $this->actingAs($user = profilingConsent(User::factory()->create()));

    // A budget big enough that Liljeholmen's places stay reachable from Hornstull —
    // so the ONLY thing keeping the dismissed place out of batch 2 is the exclusion.
    $session = livingSession($user, budget: 180);

    $rejected = Recommendation::query()->where('explore_session_id', $session->id)
        ->orderBy('position')->firstOrFail();
    $rejectedName = $rejected->score_inputs['candidate']['name'];

    $this->postJson("/recommendations/{$rejected->id}/feedback", ['event' => 'dismissed'])->assertNoContent();

    $this->travelTo(now()->addMinutes(5));
    reportPosition($session, HORNSTULL);
    $this->get("/explore/{$session->id}")->assertOk();

    $batch2 = servedNames($session, 2);

    // The heart of it. Dismissal is filtered on the way OUT, per recommendation row —
    // so a fresh batch re-ranks the same candidate pool and would happily re-pick the
    // refused place as a new, un-dismissed row. It would come straight back, one card
    // lower, and the user would reasonably conclude the button does nothing.
    expect($batch2)->not->toContain($rejectedName)
        ->and($batch2)->not->toBeEmpty();
});

it('serves fresh picks from here on request, even standing still', function () {
    feedPlace('Vinterviken', LILJEHOLMEN);

    $this->actingAs($user = profilingConsent(User::factory()->create()));
    $session = livingSession($user);

    $this->post("/explore/{$session->id}/refresh")->assertRedirect("/explore/{$session->id}");

    $latest = Recommendation::query()->where('explore_session_id', $session->id)
        ->orderByDesc('serve_group')->firstOrFail();

    // No drift test, no interval: the user asked. They may not have moved a metre —
    // they may simply have eaten.
    expect($latest->serve_group)->toBe(2)
        ->and($latest->serve_reason)->toBe(ServeReason::ManualRefresh);
});

it('never re-anchors onto the home zone', function () {
    feedPlace('Vinterviken', LILJEHOLMEN);
    feedPlace('Tantolunden', HORNSTULL);

    $this->actingAs($user = profilingConsent(User::factory()->create()));

    // The user lives in Hornstull. Stockholm testing happens from the founder's actual
    // home base, so this is not a hypothetical (PRD §16).
    DB::statement(
        'UPDATE users SET home_zone_center = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, home_zone_radius_meters = 300 WHERE id = ?',
        [HORNSTULL['lng'], HORNSTULL['lat'], $user->id],
    );

    $session = livingSession($user);

    $this->travelTo(now()->addMinutes(5));
    reportPosition($session, HORNSTULL);

    // The context event was recorded — but stripped of its coordinate on the way in, so
    // there is no position for the ranker to re-anchor onto. Walking home does not move
    // the feed onto your own street, and it cannot, because the suppression is upstream
    // of every reader.
    $this->get("/explore/{$session->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('serve.group', 1));

    expect(servedNames($session))->not->toContain('Tantolunden');
});

it('keeps the menu it has when the user walks out of the region', function () {
    // Nothing exists anywhere near Hornstull — the edge of the launch region, which is a
    // real place on a real map (PRD §8.1, "graceful degradation elsewhere").
    feedPlace('Vinterviken', LILJEHOLMEN);

    $this->actingAs($user = profilingConsent(User::factory()->create()));
    $session = livingSession($user);

    $this->travelTo(now()->addMinutes(5));
    reportPosition($session, HORNSTULL);

    /*
     * Caught driving the real app: the re-anchor fired, ranked from Hornstull, found
     * nothing reachable, and returned the empty result straight to the screen — WIPING a
     * feed that was showing cards a second earlier. Degrading gracefully means keeping
     * the last menu we could stand behind, not deleting it.
     */
    $this->get("/explore/{$session->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('opportunities.data', 1)
            ->where('opportunities.data.0.title', 'Vinterviken')
            ->where('serve.group', 1));

    // ...and the fruitless rank wrote nothing, so it left no `served_at` for the
    // min-interval guard to bite on. Without a brake, EVERY subsequent pull would run
    // the whole pipeline again — forever, for a user standing still outside the region.
    $ranks = 0;
    Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$ranks): void {
        if (str_contains($q->sql, 'insert into "recommendations"')) {
            $ranks++;
        }
    });

    $this->get("/explore/{$session->id}")->assertOk();
    $this->get("/explore/{$session->id}")->assertOk();

    expect($ranks)->toBe(0)
        ->and(Recommendation::query()->where('explore_session_id', $session->id)->count())->toBe(1);
});

it('does not blank the feed of a session whose budget has run out', function () {
    feedPlace('Vinterviken', LILJEHOLMEN);
    feedPlace('Tantolunden', HORNSTULL);

    $this->actingAs($user = profilingConsent(User::factory()->create()));
    $session = livingSession($user);   // 45 minutes

    // Three hours later. The session is still `active` — the reaper has not run — but
    // its budget is long gone, so nothing is reachable and a re-rank can only ever
    // return nothing. PRD §8.1 re-serves against the REMAINING budget; there is none.
    $this->travelTo(now()->addHours(3));
    reportPosition($session, HORNSTULL);

    $this->get("/explore/{$session->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('opportunities.data', 1));

    expect(Recommendation::query()->where('explore_session_id', $session->id)->max('serve_group'))->toBe(1);
});

it('replays every serve of the session, each on its own clock and anchor', function () {
    feedPlace('Vinterviken', LILJEHOLMEN);
    feedPlace('Tantolunden', HORNSTULL);

    $this->actingAs($user = profilingConsent(User::factory()->create()));
    $session = livingSession($user);

    $this->travelTo(now()->addMinutes(5));
    reportPosition($session, HORNSTULL);
    $this->get("/explore/{$session->id}")->assertOk();

    expect(Recommendation::query()->where('explore_session_id', $session->id)->max('serve_group'))->toBe(2);

    /*
     * The replayer used to take `served_at` from the row at position 1 and use that one
     * instant as THE clock for the session, and rank from `explore_sessions.origin` —
     * where the session began. On a multi-serve session that is not merely incomplete,
     * it is wrong in the most expensive way available: it replays the Hornstull batch
     * from Liljeholmen, an hour early, and reports the guaranteed divergence as a
     * pipeline regression. A tool that lies at that rate is worse than no tool.
     */
    $this->artisan("replay:session {$session->id}")
        ->expectsOutputToContain('serve 1')
        ->expectsOutputToContain('serve 2')
        ->expectsOutputToContain('identical across all 2 serves')
        ->assertSuccessful();
});

it('stops re-serving once the session is over', function () {
    feedPlace('Vinterviken', LILJEHOLMEN);
    feedPlace('Tantolunden', HORNSTULL);

    $this->actingAs($user = profilingConsent(User::factory()->create()));
    $session = livingSession($user);

    $this->post("/explore/{$session->id}/end");

    $this->travelTo(now()->addMinutes(5));

    // The position is refused at the door — an ended session does not accept context
    // events, so movement after the fact cannot even reach the ranker.
    $this->postJson("/explore/{$session->id}/context-events", [
        'location' => HORNSTULL + ['accuracy_m' => 20],
        'app_state' => 'foreground',
    ])->assertStatus(409);

    // And the refresh route is closed too, so neither path can wake it.
    $this->post("/explore/{$session->id}/refresh");

    $this->get("/explore/{$session->id}")->assertOk();

    // An ended session is a record, not a feed. It still replays — /kept and the
    // journal read these rows — but a budget that ran out an hour ago has no
    // "remaining budget" to score against, and re-serving one would quietly
    // resurrect a dead session as a live one.
    expect(Recommendation::query()->where('explore_session_id', $session->id)->max('serve_group'))->toBe(1);
});

it('serves one batch per walk, not two — the serve is serialised', function () {
    feedPlace('Vinterviken', LILJEHOLMEN);
    feedPlace('Tantolunden', HORNSTULL);

    $this->actingAs($user = profilingConsent(User::factory()->create()));
    $session = livingSession($user);

    $this->travelTo(now()->addMinutes(5));
    reportPosition($session, HORNSTULL);

    /*
     * Two pulls arriving together — the map view and the feed view, a page and its poll,
     * a client that retries. Found by driving the emulator: BOTH read `max(serve_group)`
     * as 1, both decided they were group 2, both planned, both persisted. The feed came
     * back with ten rows for a five-item menu and showed Centralbadsparken above
     * Centralbadsparken.
     *
     * `plan()` takes seconds, so the window between the read and the write is enormous.
     */
    $data = ExploreSessionData::fromModel($session->fresh());

    app(RankSession::class)->feedFor($data);
    app(RankSession::class)->feedFor($data);

    $rows = Recommendation::query()->where('explore_session_id', $session->id)->get();

    // One row per (group, position) — now enforced by the database too, so it cannot
    // come back even if the lock is refactored away.
    $slots = $rows->map(fn (Recommendation $r): string => "{$r->serve_group}:{$r->position}");

    expect($slots->count())->toBe($slots->unique()->count())
        ->and(Recommendation::query()->where('explore_session_id', $session->id)->max('serve_group'))->toBe(2);
});

it('lets an emulated walk re-anchor faster than a human feed would', function () {
    feedPlace('Vinterviken', LILJEHOLMEN);
    feedPlace('Tantolunden', HORNSTULL);

    $this->actingAs($user = profilingConsent(User::factory()->create()));

    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->emulated()->at(LILJEHOLMEN['lat'], LILJEHOLMEN['lng'])->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 45,
    ]);

    app(RankSession::class)->feedFor(ExploreSessionData::fromModel($session));

    /*
     * Twelve seconds later. A real feed would refuse — 120 s, so cards do not move out
     * from under a reader's thumb. But playback compresses a two-hour walk into two
     * minutes, and at 60× that courtesy means the pipeline reacts once and then watches
     * the pin cross the city in silence. Which is exactly what it did on the first walk
     * anyone drove, and it made the tool look broken when it was merely being polite.
     *
     * The DRIFT threshold is not relaxed with it: "did they actually move" is a question
     * about the world, and the emulator is supposed to be asking the real one.
     */
    $this->travelTo(now()->addSeconds(12));
    reportPosition($session, HORNSTULL);

    $this->get("/explore/{$session->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('serve.group', 2)
            ->where('serve.reason', 'move_reanchor'));
});

it('tells the client whether a session is real, so the browser never reports into a simulation', function () {
    feedPlace('Vinterviken', LILJEHOLMEN);

    $this->actingAs($user = profilingConsent(User::factory()->create()));

    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->emulated()->at(LILJEHOLMEN['lat'], LILJEHOLMEN['lng'])->create([
        'trip_id' => $trip->id, 'user_id' => $user->id,
    ]);

    /*
     * The emulator renders this very screen in an iframe. Without `context_source` on the
     * payload, `useLivingFeed` reads the OPERATOR'S REAL GEOLOCATION and posts it into the
     * emulated session — and a walk across Vasastaden re-anchors onto Liljeholmen, because
     * that is where the founder's body was. The simulation quietly became a report of the
     * operator's own position (E47, 2026-07-14).
     */
    $this->get("/explore/{$session->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('session.data.context_source', 'emulated'));
});

it('drops a serve decision that went stale while it waited for the lock', function () {
    feedPlace('Vinterviken', LILJEHOLMEN);
    feedPlace('Tantolunden', HORNSTULL);

    $this->actingAs($user = profilingConsent(User::factory()->create()));
    $session = livingSession($user);

    $this->travelTo(now()->addMinutes(5));
    reportPosition($session, HORNSTULL);

    $data = ExploreSessionData::fromModel($session->fresh());
    $rank = app(RankSession::class);

    // First pull re-anchors: group 1 → group 2.
    $rank->feedFor($data);

    /*
     * Now a serve whose decision was made against group 1 — a pull that read the state,
     * queued on the lock while the first rank ran, and has only just woken up.
     *
     * Caught driving the emulator: the interval check alone does NOT stop this. It asks
     * "has anyone served in the last N seconds?" against `served_at`, which is the clock
     * from the START of the other rank — and that rank took ten seconds. So the answer
     * came back "no", and it served again: two batches, two bills, one walk.
     *
     * Comparing the GROUP compares what we decided on against what is now true, which is
     * the actual question, and it does not care how long the other rank took.
     */
    $stale = $rank->serve($data->reAnchoredAt(new Coordinates(HORNSTULL['lat'], HORNSTULL['lng'])), ServeReason::MoveReanchor, null, seenGroup: 1);

    expect($stale)->toBe([])
        ->and(Recommendation::query()->where('explore_session_id', $session->id)->max('serve_group'))->toBe(2);
});
