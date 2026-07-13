<?php

declare(strict_types=1);

use App\Domain\Context\Data\WeatherContext;
use App\Domain\Places\Models\Place;
use App\Domain\Privacy\Actions\CoarsenExpiredLocations;
use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Domain\Trips\Queries\BuildJournal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The weather we saw, kept for good
|--------------------------------------------------------------------------
|
| Weather was fetched on every ranked session and then thrown away. The only
| residue was `weather_c` on the decision trace — a friction COEFFICIENT, and
| ambiguous in the worst possible way: 0 means "it was dry" AND "we never knew".
|
| It cannot be recovered later. Open-Meteo's forecast endpoint answers "what is
| the sky doing now"; it will not tell you about last August. And the LLM is
| never a source of facts. So an observation not written down at the time is gone
| permanently — the same asymmetry as the cost ledger.
|
*/

function weatherSession(float $precipitationMm = 0.0, float $tempC = 19.0): ExploreSession
{
    Http::fake(['api.open-meteo.com/*' => Http::response([
        'current' => [
            'temperature_2m' => $tempC,
            'precipitation' => $precipitationMm,
            'weather_code' => $precipitationMm > 0 ? 61 : 0,
            'cloud_cover' => 10,
        ],
    ])]);

    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);

    $place = Place::factory()->create([
        'name' => 'Trekanten', 'type' => 'lake', 'type_domain' => 'nature_landscape',
        'facets' => ['scenic'], 'source_tags' => ['osm' => []],
    ]);

    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(18.0206, 59.3117), 4326)::geography WHERE id = ?',
        [$place->id],
    );
    DB::statement(
        'UPDATE places_core SET h3_index = h3_lat_lng_to_cell(POINT(18.0206, 59.3117), 8)::text WHERE id = ?',
        [$place->id],
    );

    $session = ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 180,
    ]);

    app(RankSession::class)->feedFor(ExploreSessionData::fromModel($session));

    return $session->refresh();
}

it('writes down the sky it ranked under, so the journal can answer what the weather was', function () {
    $session = weatherSession(precipitationMm: 1.4, tempC: 14.0);

    // Read back through the DTO, not out of the raw jsonb — which is also how anything
    // in the app should read it. JSON has no float/int distinction, so a stored 14.0
    // decodes as the int 14; `fromTrace()` is the one place that gets normalised, and
    // asserting through it is asserting the contract rather than the storage accident.
    $observed = WeatherContext::fromTrace($session->weather);

    expect($session->weather)->not->toBeNull()
        ->and($observed->known())->toBeTrue()
        ->and($observed->temperatureC)->toBe(14.0)
        ->and($observed->precipitationMm)->toBe(1.4)
        ->and($observed->weatherCode)->toBe(61)
        ->and($observed->isWet())->toBeTrue()
        ->and($session->weather_observed_at)->not->toBeNull();
});

it('keeps the FIRST sky, not the last time someone opened the app', function () {
    $session = weatherSession(precipitationMm: 1.4, tempC: 14.0);

    // Re-rank the same session under a different sky. The snapshot is the weather we
    // DECIDED under — a session re-read at 6pm must not rewrite the afternoon it began in.
    Http::fake(['api.open-meteo.com/*' => Http::response([
        'current' => ['temperature_2m' => 28.0, 'precipitation' => 0.0, 'weather_code' => 0, 'cloud_cover' => 0],
    ])]);

    app(RankSession::class)->feedFor(ExploreSessionData::fromModel($session->refresh()));

    expect(WeatherContext::fromTrace($session->refresh()->weather)->temperatureC)->toBe(14.0);   // not 28
});

it('records nothing at all when the sky is unknown, rather than recording four nulls', function () {
    // The circuit breaker swallows an unreachable Open-Meteo and the feed is served
    // without weather (SCORING §2.5). "We looked and saw nothing" is not an observation,
    // and a row of nulls would be indistinguishable from one.
    Http::fake(['api.open-meteo.com/*' => Http::response([], 500)]);

    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id,
    ]);

    app(RankSession::class)->feedFor(ExploreSessionData::fromModel($session));

    expect($session->refresh()->weather)->toBeNull();
});

it('survives the retention pass that deletes the coordinate', function () {
    // The whole privacy argument in one test: the coordinate is the sensitive part, and
    // the sky is not. Coarsening hard-deletes where the person was standing; what the
    // weather was doing over a 460m hex is environmental context anyone could look up.
    $session = weatherSession(precipitationMm: 1.4, tempC: 14.0);

    ExploreSession::query()->whereKey($session->id)->update(['started_at' => now()->subDays(45)]);

    app(CoarsenExpiredLocations::class)();

    $session->refresh();

    expect($session->origin)->toBeNull()                   // the coordinate is gone...
        ->and($session->origin_h3_index)->not->toBeNull()  // ...the cell remains (by design)...
        ->and(WeatherContext::fromTrace($session->weather)->temperatureC)->toBe(14.0);   // ...and so does the memory.
});

it('shows the weather on the trip in the journal — as a range, not an average', function () {
    $session = weatherSession(precipitationMm: 1.4, tempC: 14.0);
    $user = User::query()->findOrFail($session->user_id);

    $journal = app(BuildJournal::class)->forUser($user->id);

    expect($journal)->toHaveCount(1)
        ->and($journal[0]['weather']['min_c'])->toBe(14.0)
        ->and($journal[0]['weather']['max_c'])->toBe(14.0)
        // A mean is true of a week nobody experienced. A wet day is a wet day.
        ->and($journal[0]['weather']['wet_observations'])->toBe(1)
        ->and($journal[0]['weather']['observations'])->toBe(1);
});

it('says nothing about the weather on a trip we never observed', function () {
    // null is not "it was dry". The journal renders nothing rather than a claim.
    $user = User::factory()->create();
    Trip::factory()->create(['user_id' => $user->id]);

    $journal = app(BuildJournal::class)->forUser($user->id);

    expect($journal[0]['weather'])->toBeNull();
});
