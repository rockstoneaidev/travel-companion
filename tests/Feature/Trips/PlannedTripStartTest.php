<?php

declare(strict_types=1);

use App\Domain\Places\Data\Coordinates;
use App\Domain\Trips\Actions\StartPlannedTrip;
use App\Domain\Trips\Enums\TravelMode;
use App\Domain\Trips\Enums\TripStatus;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Starting a planned trip (the "it just stays Planned" gap)
|--------------------------------------------------------------------------
|
| A planned trip with a location was a dead end — nameable, pinnable, and unrunnable. "Start
| exploring" is the door: it activates the trip and opens a session AT its location, attached
| to it (not clustered into a fresh one).
|
*/

function plannedTrip(User $user, ?array $anchor = ['lng' => 18.0686, 'lat' => 59.3293]): Trip
{
    $trip = Trip::factory()->create([
        'user_id' => $user->id,
        'status' => TripStatus::Planned,
        'source' => 'user',
        'started_at' => null,
        'anchor_point' => $anchor === null ? null : DB::raw("ST_GeogFromText('POINT({$anchor['lng']} {$anchor['lat']})')"),
    ]);

    return $trip->fresh();
}

it('activates a planned trip and opens a session attached to it', function () {
    $user = User::factory()->create();
    $trip = plannedTrip($user);

    $session = app(StartPlannedTrip::class)($trip, 180, TravelMode::Walk);

    // The trip is live now, and the session belongs to THIS trip — not a new clustered one.
    expect($trip->fresh()->status)->toBe(TripStatus::Active)
        ->and($trip->fresh()->started_at)->not->toBeNull()
        ->and($session->trip_id)->toBe($trip->id)
        ->and($session->status->value)->toBe('active');

    // Opened at the trip's own location.
    $origin = DB::selectOne('SELECT ST_Y(origin::geometry) lat, ST_X(origin::geometry) lng FROM explore_sessions WHERE id = ?', [$session->id]);
    expect(round((float) $origin->lat, 3))->toBe(59.329);
});

it('ends any other active trip — one live trip per person', function () {
    $user = User::factory()->create();
    $other = Trip::factory()->create(['user_id' => $user->id, 'status' => TripStatus::Active]);
    $planned = plannedTrip($user);

    app(StartPlannedTrip::class)($planned, 180, TravelMode::Walk);

    expect($other->fresh()->status)->toBe(TripStatus::Completed)
        ->and($planned->fresh()->status)->toBe(TripStatus::Active);
});

it('refuses to start a trip with neither an anchor nor a current location', function () {
    $user = User::factory()->create();
    $trip = plannedTrip($user, anchor: null);

    expect(fn () => app(StartPlannedTrip::class)($trip, 180, TravelMode::Walk))
        ->toThrow(RuntimeException::class);
});

it('starts an anchorless trip from the current location instead', function () {
    // The Fjäderholmarna case: planned by name, no anchor, but you are standing in it.
    $user = User::factory()->create();
    $trip = plannedTrip($user, anchor: null);

    $session = app(StartPlannedTrip::class)($trip, 180, TravelMode::Walk, new Coordinates(59.3291, 18.1779));

    expect($session->trip_id)->toBe($trip->id)
        ->and($trip->fresh()->status)->toBe(TripStatus::Active);

    $origin = DB::selectOne('SELECT ST_Y(origin::geometry) lat FROM explore_sessions WHERE id = ?', [$session->id]);
    expect(round((float) $origin->lat, 3))->toBe(59.329);
});

it('prefers the planner\'s anchor over a passed-in location when the trip has one', function () {
    $user = User::factory()->create();
    $trip = plannedTrip($user);   // anchored at 59.3293, 18.0686

    // Handed a wildly different "current" location, an anchored trip still starts from its anchor.
    $session = app(StartPlannedTrip::class)($trip, 180, TravelMode::Walk, new Coordinates(10.0, 10.0));

    $origin = DB::selectOne('SELECT ST_Y(origin::geometry) lat FROM explore_sessions WHERE id = ?', [$session->id]);
    expect(round((float) $origin->lat, 3))->toBe(59.329);
});

it('starts an anchorless trip from the current location via the web route', function () {
    $this->actingAs($user = User::factory()->create());
    $trip = plannedTrip($user, anchor: null);

    $this->post("/trips/{$trip->id}/start", ['lat' => 59.3291, 'lng' => 18.1779])
        ->assertRedirect();   // into the new session

    expect(ExploreSession::query()->where('trip_id', $trip->id)->where('status', 'active')->exists())->toBeTrue();
});

it('still refuses via the web route when there is neither anchor nor location', function () {
    $this->actingAs($user = User::factory()->create());
    $trip = plannedTrip($user, anchor: null);

    $this->from("/trips/{$trip->id}")
        ->post("/trips/{$trip->id}/start")
        ->assertRedirect("/trips/{$trip->id}")   // back with an error, not into a session
        ->assertSessionHas('error');

    expect(ExploreSession::query()->where('trip_id', $trip->id)->exists())->toBeFalse();
});

it('opens the trip start via the web route and lands on the live feed', function () {
    $this->actingAs($user = User::factory()->create());
    $trip = plannedTrip($user);

    $this->post("/trips/{$trip->id}/start")
        ->assertRedirect();   // to the new explore session

    expect(ExploreSession::query()->where('trip_id', $trip->id)->where('status', 'active')->exists())->toBeTrue()
        ->and($trip->fresh()->status)->toBe(TripStatus::Active);
});

it('stores and edits planned dates, and rejects a departure before the start', function () {
    $this->actingAs($user = User::factory()->create());

    // Create with dates.
    $this->post('/trips', [
        'name' => 'France, late July',
        'planned_start_at' => '2026-07-27',
        'departs_at' => '2026-08-07',
    ])->assertRedirect();

    $trip = Trip::query()->where('user_id', $user->id)->firstOrFail();
    expect($trip->planned_start_at->toDateString())->toBe('2026-07-27')
        ->and($trip->departs_at->toDateString())->toBe('2026-08-07')
        // The departure a user set is a fact, not an inference (feeds the stay-aware horizon).
        ->and($trip->departure_source)->toBe('user');

    // A departure before the start is a typo, not a plan.
    $this->patch("/trips/{$trip->id}", [
        'planned_start_at' => '2026-07-27',
        'departs_at' => '2026-07-20',
    ])->assertSessionHasErrors('departs_at');
});
