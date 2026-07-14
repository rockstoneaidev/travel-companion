<?php

declare(strict_types=1);

use App\Domain\Recommendations\Services\CompositeScorer;
use App\Domain\Recommendations\Services\FeedSelector;
use App\Domain\Recommendations\Services\ScoringModelResolver;
use App\Domain\Trips\Actions\InferTripSegments;
use App\Domain\Trips\Enums\TripSegmentKind;
use App\Domain\Trips\Models\Trip;
use App\Domain\Trips\Services\StayHorizon;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E38 — the trip model gets thick
|--------------------------------------------------------------------------
|
| Two claims, and they are the two SCORING §4.3 said would "fall out for free" once a
| departure is known. Free is a strong word, so they get tests: an evergreen place must
| go QUIET when there is a week left, and EVERYTHING must go urgent on the last morning
| — with no code anywhere that mentions "last day".
|
*/

function segmentTrip(User $user, ?CarbonImmutable $departsAt = null): Trip
{
    return Trip::query()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'source' => 'auto',
        'clustering_version' => 'v1',
        'started_at' => CarbonImmutable::parse('2026-08-01 09:00:00'),
        'departs_at' => $departsAt,
        'departure_source' => $departsAt !== null ? 'user' : null,
    ]);
}

/** Drop a background ping on the trip, at a place and a time. */
function segmentPing(Trip $trip, float $lat, float $lng, string $at): void
{
    DB::table('context_events')->insert([
        'user_id' => $trip->user_id,
        'trip_id' => $trip->id,
        'occurred_at' => $at,
        'location' => DB::raw(sprintf("ST_GeogFromText('POINT(%F %F)')", $lng, $lat)),
        'h3_index' => DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

it('calls a day you ended 400 km from your bed a travel day', function () {
    $trip = segmentTrip(User::factory()->create());

    // Stockholm in the morning, Göteborg by evening. Whatever else happened, you went
    // somewhere — and this day is also the trip's route-leg.
    segmentPing($trip, 59.3293, 18.0686, '2026-08-01 08:00:00');
    segmentPing($trip, 59.0000, 17.0000, '2026-08-01 11:00:00');
    segmentPing($trip, 58.2000, 13.5000, '2026-08-01 14:00:00');
    segmentPing($trip, 57.7089, 11.9746, '2026-08-01 17:00:00');

    $segments = app(InferTripSegments::class)($trip->id);

    expect($segments)->toHaveCount(1)
        ->and($segments[0]->kind)->toBe(TripSegmentKind::Travel)
        ->and($segments[0]->net_displacement_m)->toBeGreaterThan(390_000);
});

it('tells a day of walking a town apart from a day of doing nothing — which one number could not', function () {
    $user = User::factory()->create();

    /*
     * This is the test that justifies keeping TWO measures instead of one.
     *
     * Both days below have a net displacement of ~zero: you slept where you woke. A
     * classifier that looked only at "how far did you end up from where you started"
     * would call both of them relaxation, and be wrong about one of them.
     */

    // Day of sightseeing: back where you started, but you covered the old town.
    $walker = segmentTrip($user);
    segmentPing($walker, 59.3250, 18.0700, '2026-08-02 09:00:00');
    segmentPing($walker, 59.3350, 18.0900, '2026-08-02 11:00:00');
    segmentPing($walker, 59.3400, 18.0500, '2026-08-02 13:00:00');
    segmentPing($walker, 59.3200, 18.0400, '2026-08-02 16:00:00');
    segmentPing($walker, 59.3250, 18.0700, '2026-08-02 19:00:00');

    // Day on a beach: back where you started, and you never left the beach.
    $resting = segmentTrip(User::factory()->create());
    segmentPing($resting, 59.3250, 18.0700, '2026-08-02 09:00:00');
    segmentPing($resting, 59.3252, 18.0704, '2026-08-02 12:00:00');
    segmentPing($resting, 59.3249, 18.0698, '2026-08-02 16:00:00');
    segmentPing($resting, 59.3250, 18.0700, '2026-08-02 19:00:00');

    expect(app(InferTripSegments::class)($walker->id)[0]->kind)->toBe(TripSegmentKind::Sightseeing)
        ->and(app(InferTripSegments::class)($resting->id)[0]->kind)->toBe(TripSegmentKind::Relaxation);
});

it('holds a day it barely saw loosely', function () {
    $trip = segmentTrip(User::factory()->create());

    // Two pings. We did not watch this day; we glimpsed it. The classification still
    // happens — but it says so.
    segmentPing($trip, 59.3250, 18.0700, '2026-08-03 09:00:00');
    segmentPing($trip, 59.3252, 18.0704, '2026-08-03 19:00:00');

    expect(app(InferTripSegments::class)($trip->id)[0]->confidence)->toBeLessThan(0.3);
});

it('re-reads a day rather than accumulating opinions about it', function () {
    $trip = segmentTrip(User::factory()->create());

    segmentPing($trip, 59.3250, 18.0700, '2026-08-04 09:00:00');
    app(InferTripSegments::class)($trip->id);

    // The day goes on, and it turns out to be a travel day after all.
    segmentPing($trip, 57.7089, 11.9746, '2026-08-04 18:00:00');
    $segments = app(InferTripSegments::class)($trip->id);

    expect(DB::table('trip_segments')->where('trip_id', $trip->id)->count())->toBe(1)
        ->and($segments[0]->kind)->toBe(TripSegmentKind::Travel);
});

/*
|--------------------------------------------------------------------------
| The stay-aware horizon (SCORING §4.3)
|--------------------------------------------------------------------------
*/

it('stops shouting about a cathedral when you have a week left', function () {
    $horizon = app(StayHorizon::class);
    $trip = segmentTrip(User::factory()->create(), CarbonImmutable::parse('2026-08-08 10:00:00'));

    $at = CarbonImmutable::parse('2026-08-01 12:00:00');

    // An evergreen place: nothing closes, nothing ends. Phase 1 would have bounded its
    // slack at bedtime; stay-aware bounds it at the flight, a week out.
    $last = $horizon->lastFeasibleStart($trip->id, $at, $at->endOfDay(), closesAt: null);

    expect($last->toDateTimeString())->toBe('2026-08-08 10:00:00');

    // Which is the whole point: slack is now days, so `temporal_urgency = decay(slack, 8h)`
    // collapses to ~0 without anybody writing the word "evergreen".
    expect($at->diffInMinutes($last, false))->toBeGreaterThan(9_000);
});

it('makes everything urgent on the last morning, without a rule that mentions the last day', function () {
    $horizon = app(StayHorizon::class);
    $trip = segmentTrip(User::factory()->create(), CarbonImmutable::parse('2026-08-08 16:00:00'));

    $at = CarbonImmutable::parse('2026-08-08 09:00:00');   // departure day

    $last = $horizon->lastFeasibleStart($trip->id, $at, $at->endOfDay(), closesAt: null);

    // Seven hours, not "end of day". The horizon shrank because the trip is ending, and
    // every urgency score on the feed spikes together as a consequence of subtraction.
    expect($last->toDateTimeString())->toBe('2026-08-08 16:00:00')
        ->and($at->diffInMinutes($last, false))->toBe(420.0);
});

it('rolls a closing time forward to the last day you are still here', function () {
    $horizon = app(StayHorizon::class);
    $trip = segmentTrip(User::factory()->create(), CarbonImmutable::parse('2026-08-08 20:00:00'));

    $at = CarbonImmutable::parse('2026-08-01 12:00:00');
    $closesToday = CarbonImmutable::parse('2026-08-01 17:00:00');   // museum shuts at five

    /*
     * The subtlety this exists for: the museum shutting at 17:00 TODAY is not a deadline,
     * because you are here until the 8th and it opens again tomorrow. The real last chance
     * is 17:00 on the 8th. Bounding at today's closing — which is what Phase 1 does, quite
     * correctly, when it knows nothing about departure — would make a museum with six days
     * of slack look like it was about to vanish.
     */
    $last = $horizon->lastFeasibleStart($trip->id, $at, $at->endOfDay(), $closesToday);

    expect($last->toDateTimeString())->toBe('2026-08-08 17:00:00');
});

it('falls back to end of day when nobody has said when the trip ends', function () {
    $horizon = app(StayHorizon::class);
    $trip = segmentTrip(User::factory()->create());   // no departs_at — the Phase 1 case

    $at = CarbonImmutable::parse('2026-08-01 12:00:00');

    // Not a guess, not an estimate: the Phase 1 horizon, unchanged. A departure we invented
    // would make the whole feed shout on a day the traveller has a week left, and there is
    // no symptom on screen that would ever point back at the cause.
    expect($horizon->lastFeasibleStart($trip->id, $at, $at->endOfDay(), null)->toDateTimeString())
        ->toBe($at->endOfDay()->toDateTimeString());
});

/*
|--------------------------------------------------------------------------
| Day-scoped repetition (SCORING §5.2)
|--------------------------------------------------------------------------
*/

it('penalises the third church of the DAY like the third church of a feed', function () {
    $model = app(ScoringModelResolver::class)->resolve();
    $scorer = new CompositeScorer($model);
    $selector = new FeedSelector($model, $scorer);

    $candidate = fn (string $domain, float $fit): array => [
        'place_id' => Str::uuid7()->toString(),
        'type_domain' => $domain,
        'facets' => [],
        'friction_raw' => 0.0,
        'total_minutes' => 30,
        'sub_scores' => ['personal_fit' => $fit, 'temporal_urgency' => 0.5, 'uniqueness' => 0.5, 'confidence' => 0.9, 'novelty' => 0.5],
    ];

    // A church the user would love, and a park they would merely like.
    $pool = [$candidate('religious_sacred', 0.95), $candidate('nature_landscape', 0.6)];

    // Fresh day: the church wins on merit. Nothing has happened yet.
    expect($selector->select($pool, 'radius', 0.8, 1)[0]['type_domain'])->toBe('religious_sacred');

    /*
     * Same feed, same scores — but they have already been shown two churches today, across
     * earlier pulls. Session-scoped, this feed knows nothing about that and cheerfully
     * serves a third. Day-scoped, the count carries: 0.5 × 2 = full penalty, and the park
     * takes the slot.
     *
     * The user's experience is a day with three churches in it. Which side of a session
     * boundary they fell on is our problem, not theirs.
     */
    $seen = ['religious_sacred' => 2];

    expect($selector->select($pool, 'radius', 0.8, 1, $seen)[0]['type_domain'])->toBe('nature_landscape');
});
