<?php

declare(strict_types=1);

use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Places\Models\Place;
use App\Domain\Trips\Enums\ExploreSessionStatus;
use App\Domain\Trips\Enums\TripSource;
use App\Domain\Trips\Enums\TripStatus;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| The session round-trip over /api/v1 (PRD §14.5)
|--------------------------------------------------------------------------
|
| stockholmOrigin() is Liljeholmen, the test region (PRD §8.0) — tests/Pest.php.
|
*/

it('starts an explore session and resolves an implicit trip behind it', function () {
    Sanctum::actingAs($user = User::factory()->create());

    $response = $this->postJson('/api/v1/explore-sessions', [
        'origin' => stockholmOrigin(),
        'time_budget_minutes' => 180,
        'travel_mode' => 'walk',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.travel_mode', 'walk')
        ->assertJsonPath('data.time_budget_minutes', 180)
        ->assertJsonPath('data.origin.lat', 59.31)
        ->assertJsonPath('data.trip.source', 'auto')
        ->assertJsonPath('data.trip.status', 'active');

    expect($response->json('data.reach_meters'))->toBeGreaterThan(0);

    $trip = Trip::query()->sole();

    expect($trip->user_id)->toBe($user->id)
        ->and($trip->source)->toBe(TripSource::Auto)
        ->and($trip->status)->toBe(TripStatus::Active)
        ->and($trip->anchor_point?->lat)->toEqualWithDelta(59.31, 0.0001);
});

it('runs the whole session round-trip: start → feed → context event → end', function () {
    Sanctum::actingAs(User::factory()->create());

    $sessionId = $this->postJson('/api/v1/explore-sessions', [
        'origin' => stockholmOrigin(),
        'time_budget_minutes' => 120,
        'travel_mode' => 'walk',
        'heading' => 84,
        'destination_point' => ['lat' => 59.3300, 'lng' => 18.0700],
    ])->assertCreated()->json('data.id');

    $this->getJson("/api/v1/explore-sessions/{$sessionId}")
        ->assertOk()
        ->assertJsonPath('data.heading', 84)
        ->assertJsonPath('data.destination_point.lat', 59.33);

    $this->getJson("/api/v1/explore-sessions/{$sessionId}/opportunities")
        ->assertOk()
        ->assertJsonPath('data', [])                                   // nothing scouted yet — E5
        ->assertJsonPath('meta.ordering', 'distance')                  // and nothing ranked yet — E7
        ->assertJsonPath('meta.scoring_model_version', null);

    $this->postJson("/api/v1/explore-sessions/{$sessionId}/context-events", [
        'timestamp' => '2026-07-13T15:22:00+02:00',
        'location' => ['lat' => 59.3105, 'lng' => 18.0210, 'accuracy_m' => 42],
        'movement' => ['mode' => 'walking', 'speed_mps' => 1.2, 'heading' => 84],
        'app_state' => 'foreground',
        'battery' => ['level' => 0.64, 'low_power_mode' => false],
        'user_context' => ['available_minutes' => 90, 'companions' => ['partner']],
    ])->assertCreated()
        ->assertJsonPath('data.movement_mode', 'walking')
        ->assertJsonPath('data.has_location', true);

    $this->assertDatabaseHas('context_events', [
        'explore_session_id' => $sessionId,
        'movement_mode' => 'walking',
        'available_minutes' => 90,
    ]);

    $this->postJson("/api/v1/explore-sessions/{$sessionId}/end")
        ->assertOk()
        ->assertJsonPath('data.status', 'ended');

    $session = ExploreSession::query()->findOrFail($sessionId);

    expect($session->status)->toBe(ExploreSessionStatus::Ended)
        ->and($session->ended_at)->not->toBeNull()
        // The trip outlives its sessions — that is what it is for (PRD §6.6).
        ->and($session->trip->status)->toBe(TripStatus::Active);
});

it('serves the opportunities the world model can currently supply, nearest first', function () {
    Sanctum::actingAs(User::factory()->create());

    // Two places inside a walking session's reach, one far outside it.
    $near = Place::factory()->create(['location' => DB::raw("ST_GeogFromText('POINT(18.0210 59.3105)')")]);
    $further = Place::factory()->create(['location' => DB::raw("ST_GeogFromText('POINT(18.0300 59.3160)')")]);
    $unreachable = Place::factory()->create(['location' => DB::raw("ST_GeogFromText('POINT(11.9746 57.7089)')")]); // Gothenburg

    foreach ([$near, $further, $unreachable] as $place) {
        Opportunity::factory()->create([
            'place_id' => $place->id,
            'status' => OpportunityStatus::Scored,
            'expires_at' => now()->addHours(3),
        ]);
    }

    // An expired one at the nearest place must not be served.
    Opportunity::factory()->create([
        'place_id' => $near->id,
        'status' => OpportunityStatus::Scored,
        'expires_at' => now()->subMinute(),
    ]);

    $sessionId = $this->postJson('/api/v1/explore-sessions', [
        'origin' => stockholmOrigin(),
        'time_budget_minutes' => 180,
        'travel_mode' => 'walk',
    ])->json('data.id');

    $response = $this->getJson("/api/v1/explore-sessions/{$sessionId}/opportunities")->assertOk();

    expect($response->json('data.*.place.id'))->toBe([$near->id, $further->id]);
    expect($response->json('data.0.distance_meters'))->toBeLessThan($response->json('data.1.distance_meters'));
    expect($response->json('data.0'))->not->toHaveKey('scores');   // E7 adds scores; nothing fakes them now
});

it('refuses a context event on a session that is over', function () {
    Sanctum::actingAs($user = User::factory()->create());

    $session = ExploreSession::factory()->ended()->create([
        'trip_id' => Trip::factory()->create(['user_id' => $user->id]),
        'user_id' => $user->id,
    ]);

    $this->postJson("/api/v1/explore-sessions/{$session->id}/context-events", [
        'location' => ['lat' => 59.31, 'lng' => 18.02],
    ])->assertConflict();

    $this->assertDatabaseCount('context_events', 0);
});

it('refuses to end a session twice', function () {
    Sanctum::actingAs($user = User::factory()->create());

    $session = ExploreSession::factory()->ended()->create([
        'trip_id' => Trip::factory()->create(['user_id' => $user->id]),
        'user_id' => $user->id,
    ]);

    $this->postJson("/api/v1/explore-sessions/{$session->id}/end")->assertConflict();
});

it('validates the origin, the budget and the mode', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/explore-sessions', [
        'origin' => ['lat' => 120.0, 'lng' => 18.02],       // off the planet
        'time_budget_minutes' => 5000,                       // over the cap
        'travel_mode' => 'teleport',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['origin.lat', 'time_budget_minutes', 'travel_mode']);
});

/*
|--------------------------------------------------------------------------
| Authorization — location is the most sensitive thing we hold (PRD §16)
|--------------------------------------------------------------------------
*/

it('does not let a user read another user\'s explore session', function () {
    $owner = User::factory()->create();
    $session = ExploreSession::factory()->create([
        'trip_id' => Trip::factory()->create(['user_id' => $owner->id]),
        'user_id' => $owner->id,
    ]);

    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/v1/explore-sessions/{$session->id}")->assertForbidden();
    $this->getJson("/api/v1/explore-sessions/{$session->id}/opportunities")->assertForbidden();
    $this->postJson("/api/v1/explore-sessions/{$session->id}/end")->assertForbidden();
    $this->postJson("/api/v1/explore-sessions/{$session->id}/context-events", [
        'location' => ['lat' => 59.31, 'lng' => 18.02],
    ])->assertForbidden();
});

it('requires authentication', function () {
    $this->postJson('/api/v1/explore-sessions', [
        'origin' => stockholmOrigin(),
        'time_budget_minutes' => 60,
        'travel_mode' => 'walk',
    ])->assertUnauthorized();
});
