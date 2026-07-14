<?php

declare(strict_types=1);

use App\Domain\Privacy\Actions\CoarsenExpiredLocations;
use App\Domain\Privacy\Actions\CoarsenExpiredTraces;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Jobs\Privacy\EnforceRetentionJob;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E17 — retention, executed (PRD §16, GDPR Art. 5)
|--------------------------------------------------------------------------
|
| "Done when: retention jobs run on schedule; deletion verified by test." So
| these tests go and look at the raw column. A retention policy nobody checked
| is a policy nobody has.
|
| The design in one line: COARSEN, don't erase. Keep the cell (where, roughly)
| and the derived signals, because those are what the pipeline learns from. Drop
| the coordinate, because that is what identifies a person's doorway.
|
*/

function contextPing(User $user, ExploreSession $session, float $lat, float $lng, string $occurredAt): int
{
    return (int) DB::selectOne(
        'INSERT INTO context_events (explore_session_id, trip_id, user_id, occurred_at, location, accuracy_meters, movement_mode, created_at, updated_at)
         VALUES (?, ?, ?, ?, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, 12, ?, now(), now())
         RETURNING id',
        [$session->id, $session->trip_id, $user->id, $occurredAt, $lng, $lat, 'walking'],
    )->id;
}

function sessionAt(User $user, float $lat, float $lng, string $startedAt): ExploreSession
{
    $trip = Trip::factory()->create(['user_id' => $user->id]);

    $session = ExploreSession::factory()->at($lat, $lng)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'started_at' => $startedAt,
    ]);

    DB::table('trips')->where('id', $trip->id)->update([
        'anchor_point' => DB::raw("ST_SetSRID(ST_MakePoint({$lng}, {$lat}), 4326)::geography"),
        'created_at' => $startedAt,
    ]);

    return $session;
}

it('coarsens a raw ping to its cell and hard-deletes the coordinate', function () {
    $user = User::factory()->create();
    $session = sessionAt($user, 59.3103, 18.0227, now()->subDays(40)->toDateTimeString());

    $id = contextPing($user, $session, 59.3103, 18.0227, now()->subDays(40)->toDateTimeString());

    app(CoarsenExpiredLocations::class)();

    $row = DB::table('context_events')->where('id', $id)->first();

    // The cell survives — coarse presence is what the pipeline actually learns from.
    expect($row->h3_index)->not->toBeNull()
        // The coordinate does not. Hard-deleted: not soft-deleted, not archived, not
        // "excluded from queries". Storage limitation means the data is GONE.
        ->and($row->location)->toBeNull()
        ->and($row->accuracy_meters)->toBeNull()
        // ...and the derived signal is untouched. Erasing that too would throw away
        // the product; keeping the coordinate would throw away the promise.
        ->and($row->movement_mode)->toBe('walking');
});

it('leaves data inside the retention window completely alone', function () {
    $user = User::factory()->create();
    $session = sessionAt($user, 59.3103, 18.0227, now()->subDays(3)->toDateTimeString());

    $id = contextPing($user, $session, 59.3103, 18.0227, now()->subDays(3)->toDateTimeString());

    app(CoarsenExpiredLocations::class)();

    // Three days old. The window is thirty. A retention job that is too eager is a
    // broken product, not a cautious one.
    expect(DB::table('context_events')->where('id', $id)->first()->location)->not->toBeNull();
});

it('coarsens session origins and trip anchors on the same schedule', function () {
    $user = User::factory()->create();
    $session = sessionAt($user, 59.3103, 18.0227, now()->subDays(40)->toDateTimeString());

    $report = app(CoarsenExpiredLocations::class)();

    $row = DB::table('explore_sessions')->where('id', $session->id)->first();

    expect($row->origin)->toBeNull()
        ->and($row->destination_point)->toBeNull()
        // origin_h3_index was designed for exactly this — the migration says so.
        ->and($row->origin_h3_index)->not->toBeNull();

    expect(DB::table('trips')->where('id', $session->trip_id)->first()->anchor_point)->toBeNull()
        ->and($report->total())->toBeGreaterThan(0);
});

it('strips coordinates from an old trace but keeps the trace', function () {
    $user = User::factory()->create(['research_consent' => false]);
    $session = sessionAt($user, 59.3103, 18.0227, now()->subDays(40)->toDateTimeString());

    DB::table('recommendations')->insert([
        'id' => DB::raw('gen_random_uuid()'),
        'user_id' => $user->id,
        'explore_session_id' => $session->id,
        'trip_id' => $session->trip_id,
        'opportunity_id' => null,
        'position' => 1,
        'scores' => json_encode([]),
        'score_inputs' => json_encode(['candidate' => [
            'name' => 'Trekanten', 'lat' => 59.3117, 'lng' => 18.0206, 'h3_index' => '88086', 'facets' => ['scenic'],
        ]]),
        'scoring_model_version' => 'v1',
        'taxonomy_version' => 1,
        'served_at' => now()->subDays(40),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(CoarsenExpiredTraces::class)();

    $candidate = json_decode(DB::table('recommendations')->value('score_inputs'), true)['candidate'];

    // The trace SURVIVES — it is how we answer "why did I get this suggestion?", and
    // the replayer needs it (PRD §15.2). Keeping the decision does not require
    // keeping the coordinates.
    expect($candidate)->toHaveKeys(['name', 'h3_index', 'facets'])
        ->and($candidate['name'])->toBe('Trekanten')
        ->and($candidate)->not->toHaveKey('lat')
        ->and($candidate)->not->toHaveKey('lng');
});

it('coarsens the serve anchor to its H3 cell, and keeps the cell', function () {
    $user = User::factory()->create(['research_consent' => false]);
    $session = sessionAt($user, 59.3103, 18.0227, now()->subDays(40)->toDateTimeString());

    DB::table('recommendations')->insert([
        'id' => DB::raw('gen_random_uuid()'),
        'user_id' => $user->id,
        'explore_session_id' => $session->id,
        'trip_id' => $session->trip_id,
        'opportunity_id' => null,
        'position' => 1,
        'serve_group' => 2,
        'serve_reason' => 'move_reanchor',
        // Where the USER was standing when we ranked this batch — not where the place
        // is. The living feed (E46) re-anchors as they walk, so a session records a
        // TRAIL of these, which is a strictly larger disclosure than one session origin.
        'anchor' => DB::raw("ST_GeogFromText('SRID=4326;POINT(18.0345 59.3155)')"),
        'anchor_h3_index' => '881f1d4881fffff',
        'scores' => json_encode([]),
        'score_inputs' => json_encode(['candidate' => ['name' => 'Tantolunden', 'h3_index' => '88086']]),
        'scoring_model_version' => 'v1',
        'taxonomy_version' => 1,
        'served_at' => now()->subDays(40),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(CoarsenExpiredTraces::class)();

    $row = DB::table('recommendations')->first();

    // The coordinate is gone; the res-8 cell carries the geography from here on —
    // exactly what happens to `candidate.lat/lng`, on the same 30-day clock. A new
    // precise-location column that the retention job does not know about is a
    // retention policy that quietly stopped being true.
    expect($row->anchor)->toBeNull()
        ->and($row->anchor_h3_index)->toBe('881f1d4881fffff')
        // ...and the decision itself survives, which is the whole point of coarsening
        // rather than deleting.
        ->and($row->serve_reason)->toBe('move_reanchor');
});

it('exempts a research-consenting account — and nobody else', function () {
    // The sharp edge of the whole epic. Get this predicate backwards and you
    // silently retain precise location on every user who never opted in.
    $consenting = User::factory()->create(['research_consent' => true]);
    $ordinary = User::factory()->create(['research_consent' => false]);

    foreach ([$consenting, $ordinary] as $user) {
        $session = sessionAt($user, 59.3103, 18.0227, now()->subDays(40)->toDateTimeString());

        DB::table('recommendations')->insert([
            'id' => DB::raw('gen_random_uuid()'),
            'user_id' => $user->id,
            'explore_session_id' => $session->id,
            'trip_id' => $session->trip_id,
            'opportunity_id' => null,
            'position' => 1,
            'scores' => json_encode([]),
            'score_inputs' => json_encode(['candidate' => ['name' => 'Trekanten', 'lat' => 59.3117, 'lng' => 18.0206]]),
            'scoring_model_version' => 'v1',
            'taxonomy_version' => 1,
            'served_at' => now()->subDays(40),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    app(CoarsenExpiredTraces::class)();

    $keptPrecision = json_decode(DB::table('recommendations')->where('user_id', $consenting->id)->value('score_inputs'), true);
    $coarsened = json_decode(DB::table('recommendations')->where('user_id', $ordinary->id)->value('score_inputs'), true);

    // Full precision for the gold-trace suite (§15.2), for the account that ASKED.
    expect($keptPrecision['candidate'])->toHaveKey('lat');

    // ...and coarsened for everyone else, on schedule, whether or not anyone
    // remembers this exemption exists.
    expect($coarsened['candidate'])->not->toHaveKey('lat');
});

it('runs on a schedule, not on someone remembering', function () {
    $scheduled = collect(app(Schedule::class)->events())
        ->contains(fn ($event): bool => str_contains($event->description ?? '', EnforceRetentionJob::class));

    // A retention policy that depends on a human running it is not a policy, it is
    // an intention. E17's done-when is "retention jobs run ON SCHEDULE".
    expect($scheduled)->toBeTrue();
});
