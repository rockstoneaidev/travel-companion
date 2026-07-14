<?php

declare(strict_types=1);

use App\Domain\Context\Enums\ContextSource;
use App\Domain\Feedback\Actions\DetectVisits;
use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Places\Models\Place;
use App\Domain\Profiles\Models\UserTasteProfile;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E37 — the golden label goes dense
|--------------------------------------------------------------------------
|
| The north star counts places the traveller ACTUALLY WENT TO. Phase 1 could only ask,
| and a label gathered by asking measures the kind of person who answers prompts, not the
| kind of place worth going to. A dwell is not a self-report: if somebody stood in a
| churchyard for twenty minutes, they went to the church.
|
| The four things that must be true: it detects a visit, it does not mistake a pause for
| one, it never overrules a human who said they did not go, and it never lets an
| operator's synthetic walk teach a real taste profile.
|
*/

const CHURCH_LAT = 59.3200;
const CHURCH_LNG = 18.0700;

/** A recommendation for a place at a spot — the thing a visit is a visit TO. */
function dwellRecommendation(User $user, Trip $trip, float $lat, float $lng, ContextSource $source = ContextSource::Device): Recommendation
{
    $place = Place::factory()->create([
        'name' => 'Katarina kyrka',
        'location' => DB::raw(sprintf("ST_GeogFromText('POINT(%F %F)')", $lng, $lat)),
        'h3_index' => DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c,
    ]);

    $opportunityId = (string) Str::uuid7();

    DB::table('opportunities')->insert([
        'id' => $opportunityId,
        'place_id' => $place->id,
        'title' => $place->name,
        'kind' => 'evergreen',
        'status' => 'live',
        'h3_index' => $place->h3_index,
        'expires_at' => CarbonImmutable::parse('2026-09-01 00:00:00'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Recommendation::query()->create([
        'opportunity_id' => $opportunityId,
        'trip_id' => $trip->id,
        'user_id' => $user->id,
        'position' => 1,
        'scoring_model_version' => 'v1',
        'taxonomy_version' => 1,
        'context_source' => $source,
        'served_at' => CarbonImmutable::parse('2026-08-01 09:00:00'),
        'score_inputs' => ['candidate' => ['facets' => ['contemplative'], 'type_domain' => 'religious_sacred']],
    ]);
}

/** Stand somewhere, for a while. */
function dwellAt(Trip $trip, float $lat, float $lng, string $from, int $minutes): void
{
    $at = CarbonImmutable::parse($from);

    for ($i = 0; $i <= $minutes; $i += 5) {
        DB::table('context_events')->insert([
            'user_id' => $trip->user_id,
            'trip_id' => $trip->id,
            'occurred_at' => $at->addMinutes($i),
            // A metre or two of GPS jitter, as a real handset produces.
            'location' => DB::raw(sprintf("ST_GeogFromText('POINT(%F %F)')", $lng + ($i % 3) * 0.00001, $lat)),
            'h3_index' => DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

function dwellTrip(User $user, ContextSource $source = ContextSource::Device): Trip
{
    return Trip::query()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'source' => 'auto',
        'clustering_version' => 'v1',
        'context_source' => $source,
        'started_at' => CarbonImmutable::parse('2026-08-01 08:00:00'),
    ]);
}

it('knows you went, without asking', function () {
    $user = profilingConsent(User::factory()->create());
    $trip = dwellTrip($user);
    $recommendation = dwellRecommendation($user, $trip, CHURCH_LAT, CHURCH_LNG);

    // Twenty minutes in the churchyard. Nobody tapped anything.
    dwellAt($trip, CHURCH_LAT, CHURCH_LNG, '2026-08-01 14:00:00', 20);

    $detected = app(DetectVisits::class)($trip->id);

    expect($detected)->toBe([$recommendation->id]);

    $visit = DB::table('recommendation_feedback')
        ->where('recommendation_id', $recommendation->id)
        ->where('event', FeedbackEvent::Visited->value)
        ->first();

    expect($visit)->not->toBeNull();

    // And it says how it knows. A model that one day wants to weigh a detection
    // differently from a confirmation can only do that if we wrote down which it was.
    expect(json_decode($visit->metadata, true)['source'])->toBe('detected');
});

it('does not mistake a pause for a visit', function () {
    $user = profilingConsent(User::factory()->create());
    $trip = dwellTrip($user);
    dwellRecommendation($user, $trip, CHURCH_LAT, CHURCH_LNG);

    // Four minutes outside the church: a red light, a look at the map, a photograph. This
    // is the difference between a companion that knows things and one that assumes them.
    dwellAt($trip, CHURCH_LAT, CHURCH_LNG, '2026-08-01 14:00:00', 4);

    expect(app(DetectVisits::class)($trip->id))->toBeEmpty();
});

it('does not claim you visited a place you merely walked past', function () {
    $user = profilingConsent(User::factory()->create());
    $trip = dwellTrip($user);
    dwellRecommendation($user, $trip, CHURCH_LAT, CHURCH_LNG);

    // A long lunch 600 m away. You dwelled — just not there.
    dwellAt($trip, CHURCH_LAT + 0.0055, CHURCH_LNG, '2026-08-01 13:00:00', 40);

    expect(app(DetectVisits::class)($trip->id))->toBeEmpty();
});

it('never tells a person they are wrong about their own day', function () {
    $user = profilingConsent(User::factory()->create());
    $trip = dwellTrip($user);
    $recommendation = dwellRecommendation($user, $trip, CHURCH_LAT, CHURCH_LNG);

    // They were asked, and they said they did not go.
    DB::table('recommendation_feedback')->insert([
        'recommendation_id' => $recommendation->id,
        'event' => FeedbackEvent::VisitPromptDeclined->value,
        'metadata' => '{}',
        'occurred_at' => CarbonImmutable::parse('2026-08-01 18:00:00'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // ...and the coordinates disagree. The coordinates are a GPS fix; the person is a
    // person. Overruling them here would be the product informing somebody that they
    // visited a building they have just said they never entered.
    dwellAt($trip, CHURCH_LAT, CHURCH_LNG, '2026-08-01 14:00:00', 30);

    expect(app(DetectVisits::class)($trip->id))->toBeEmpty()
        ->and(DB::table('recommendation_feedback')
            ->where('recommendation_id', $recommendation->id)
            ->where('event', FeedbackEvent::Visited->value)
            ->count())->toBe(0);
});

it('counts one visit, not two, when a detection meets a confirmation', function () {
    $user = profilingConsent(User::factory()->create());
    $trip = dwellTrip($user);
    $recommendation = dwellRecommendation($user, $trip, CHURCH_LAT, CHURCH_LNG);

    dwellAt($trip, CHURCH_LAT, CHURCH_LNG, '2026-08-01 14:00:00', 30);

    app(DetectVisits::class)($trip->id);
    app(DetectVisits::class)($trip->id);   // the job runs again on the next ping

    /*
     * `visited` carries η .30 — the strongest signal the learner has (SCORING §4.1). A
     * label that fires twice is a label with twice the weight, and that is a bug that
     * makes no noise anywhere: the taste profile simply drifts, faster than it should,
     * toward whatever the traveller happened to stand still next to.
     */
    expect(DB::table('recommendation_feedback')
        ->where('recommendation_id', $recommendation->id)
        ->where('event', FeedbackEvent::Visited->value)
        ->count())->toBe(1);
});

it('will not let an operator’s synthetic walk teach a real taste profile', function () {
    $user = profilingConsent(User::factory()->create());
    $trip = dwellTrip($user, ContextSource::Emulated);
    $recommendation = dwellRecommendation($user, $trip, CHURCH_LAT, CHURCH_LNG, ContextSource::Emulated);

    $before = UserTasteProfile::for((int) $user->id)->facet_weights;

    dwellAt($trip, CHURCH_LAT, CHURCH_LNG, '2026-08-01 14:00:00', 30);

    expect(app(DetectVisits::class)($trip->id))->toBe([$recommendation->id]);

    /*
     * The ledger IS written — an emulated visit is a real event in the emulator, and the
     * console exists to watch the pipeline behave. What must not move is the PROFILE.
     *
     * This is the trap E37 walked straight into and why detection goes through
     * RecordFeedback rather than the ledger: a founder driving the position emulator down
     * a street would otherwise be teaching their own taste model to love every church they
     * happened to park a synthetic pin next to — and the profile learns from sparse data,
     * so a debugging session would show up in real recommendations for weeks.
     */
    expect(DB::table('recommendation_feedback')
        ->where('recommendation_id', $recommendation->id)
        ->where('event', FeedbackEvent::Visited->value)
        ->count())->toBe(1)
        ->and(UserTasteProfile::for((int) $user->id)->fresh()->facet_weights)->toEqual($before);
});
