<?php

declare(strict_types=1);

use App\Domain\Privacy\Actions\DecideInferredHomeZone;
use App\Domain\Privacy\Actions\InferHomeZone;
use App\Domain\Privacy\Actions\UpdatePrivacySettings;
use App\Domain\Privacy\Models\InferredHomeZone;
use App\Domain\Privacy\Services\HomeZone;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E40 — inferring the home zone, without ever writing where it is
|--------------------------------------------------------------------------
|
| The paradox: to find home you read the overnight trace, but home's whole promise (ROPA
| §8) is that the precise coordinate is NEVER written. The resolution is that the proposal
| is a hexagon, not a coordinate, and even a confirmed zone centres on the hexagon's middle.
| These tests are mostly about the coordinate that must not exist.
|
*/

/** Put an event at a place, at a time, for a user on a trip. */
function homeTrip(int $userId): string
{
    return Trip::factory()->create(['user_id' => $userId])->id;
}

function homeEvent(int $userId, float $lat, float $lng, string $at): void
{
    static $trips = [];
    $trips[$userId] ??= homeTrip($userId);

    DB::table('context_events')->insert([
        'user_id' => $userId,
        'trip_id' => $trips[$userId],
        'occurred_at' => $at,
        'location' => DB::raw(sprintf("ST_GeogFromText('POINT(%F %F)')", $lng, $lat)),
        'h3_index' => DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** A day that starts and ends at home, with an outing in the middle. */
function dayFromHome(int $userId, string $date, float $homeLat, float $homeLng): void
{
    homeEvent($userId, $homeLat, $homeLng, "{$date} 07:30:00");             // wake up at home
    homeEvent($userId, $homeLat + 0.03, $homeLng + 0.03, "{$date} 13:00:00"); // out and about
    homeEvent($userId, $homeLat, $homeLng, "{$date} 22:30:00");             // back home to sleep
}

const HOME_LAT = 59.3120;
const HOME_LNG = 18.0700;

it('finds home from the ends of the day, needing no timezone', function () {
    $user = User::factory()->create();

    // A fortnight of days that begin and end in the same place.
    for ($d = 1; $d <= 14; $d++) {
        dayFromHome((int) $user->id, sprintf('2026-06-%02d', $d), HOME_LAT, HOME_LNG);
    }

    $zone = app(InferHomeZone::class)((int) $user->id);

    expect($zone)->not->toBeNull()
        ->and($zone->status)->toBe('proposed')
        ->and($zone->nights_observed)->toBe(14);

    // The proposed cell is the home cell.
    $homeCell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [HOME_LNG, HOME_LAT])->c;
    expect($zone->h3_index)->toBe($homeCell);
});

it('stores a hexagon and never a coordinate', function () {
    $user = User::factory()->create();
    for ($d = 1; $d <= 14; $d++) {
        dayFromHome((int) $user->id, sprintf('2026-06-%02d', $d), HOME_LAT, HOME_LNG);
    }

    app(InferHomeZone::class)((int) $user->id);

    // The table itself must have no coordinate column carrying a value. The most direct
    // possible assertion: the row has an h3_index, and the schema has nothing finer.
    $row = (array) DB::table('inferred_home_zones')->where('user_id', $user->id)->first();

    expect($row['h3_index'])->not->toBeNull();

    // No column named lat/lng/location/geometry exists to leak into.
    $columns = array_keys($row);
    foreach (['lat', 'lng', 'latitude', 'longitude', 'location', 'geometry', 'center'] as $forbidden) {
        expect($columns)->not->toContain($forbidden);
    }
});

it('will not propose a home it is not sure of', function () {
    $user = User::factory()->create();

    // A week split evenly between two bases — genuinely ambiguous. Suppressing the wrong one
    // is worse than suppressing neither, so we propose neither.
    for ($d = 1; $d <= 5; $d++) {
        dayFromHome((int) $user->id, sprintf('2026-06-%02d', $d), HOME_LAT, HOME_LNG);
        dayFromHome((int) $user->id, sprintf('2026-06-1%d', $d), 59.4000, 18.2000);
    }

    expect(app(InferHomeZone::class)((int) $user->id))->toBeNull();
});

it('says nothing on a short trip', function () {
    $user = User::factory()->create();

    // Three nights in one place — a weekend away, not a home.
    for ($d = 1; $d <= 3; $d++) {
        dayFromHome((int) $user->id, sprintf('2026-06-%02d', $d), HOME_LAT, HOME_LNG);
    }

    expect(app(InferHomeZone::class)((int) $user->id))->toBeNull();
});

it('does not infer over a home the user has already declared', function () {
    $user = User::factory()->create();
    app(UpdatePrivacySettings::class)
        ->declareHomeZone((int) $user->id, HOME_LAT, HOME_LNG, 300);

    for ($d = 1; $d <= 14; $d++) {
        dayFromHome((int) $user->id, sprintf('2026-06-%02d', $d), 59.5, 18.5);
    }

    // Their word is the answer. Nothing to infer.
    expect(app(InferHomeZone::class)((int) $user->id))->toBeNull();
});

it('activates a confirmed zone on the hexagon centroid — coarse by construction', function () {
    $user = User::factory()->create();
    for ($d = 1; $d <= 14; $d++) {
        dayFromHome((int) $user->id, sprintf('2026-06-%02d', $d), HOME_LAT, HOME_LNG);
    }

    $zone = app(InferHomeZone::class)((int) $user->id);
    app(DecideInferredHomeZone::class)->confirm($zone);

    // It is now a real, suppressing home zone.
    $home = HomeZone::forUser((int) $user->id);
    expect($home->declared())->toBeTrue()
        ->and($home->contains(HOME_LAT, HOME_LNG))->toBeTrue();

    // ...and its centre is the CELL centroid, deliberately not the actual sleeping spot.
    // The two are in the same hexagon but need not be the same point — which is the whole
    // safety property: even a confirmed inferred zone does not know your doorstep.
    $stored = DB::selectOne(
        'SELECT ST_Y(home_zone_center::geometry) AS lat, ST_X(home_zone_center::geometry) AS lng FROM users WHERE id = ?',
        [$user->id],
    );
    $centroidCell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$stored->lng, $stored->lat])->c;
    expect($centroidCell)->toBe($zone->h3_index);

    expect($zone->fresh()->status)->toBe('confirmed');
});

it('remembers a rejection, and does not ask again about the same cell', function () {
    $user = User::factory()->create();
    for ($d = 1; $d <= 14; $d++) {
        dayFromHome((int) $user->id, sprintf('2026-06-%02d', $d), HOME_LAT, HOME_LNG);
    }

    $zone = app(InferHomeZone::class)((int) $user->id);
    app(DecideInferredHomeZone::class)->reject($zone);

    // More evidence arrives — another week sleeping in the same hotel.
    for ($d = 15; $d <= 21; $d++) {
        dayFromHome((int) $user->id, sprintf('2026-06-%02d', $d), HOME_LAT, HOME_LNG);
    }

    $again = app(InferHomeZone::class)((int) $user->id);

    // "No, that's the hotel" is durable. Stronger evidence that they slept there is not a
    // reason to re-ask — it is the reason they said no.
    expect($again->status)->toBe('rejected')
        ->and(InferredHomeZone::query()->where('user_id', $user->id)->where('status', 'proposed')->count())->toBe(0);
});
