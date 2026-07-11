<?php

declare(strict_types=1);

use App\Domain\Trips\Enums\ExploreSessionStatus;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| The same round-trip over Inertia (CLAUDE.md — one domain, two delivery surfaces)
|--------------------------------------------------------------------------
*/

it('runs the session round-trip over the web surface', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get('/explore')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('explore/index')
            ->where('activeSession', null)
            ->has('travelModeOptions', 3));

    $response = $this->post('/explore', [
        'origin' => stockholmOrigin(),
        'time_budget_minutes' => 180,
        'travel_mode' => 'walk',
    ]);

    $session = ExploreSession::query()->sole();

    $response->assertRedirect("/explore/{$session->id}");

    // The web surface produced exactly what the API surface would have.
    expect($session->user_id)->toBe($user->id)
        ->and($session->status)->toBe(ExploreSessionStatus::Active)
        ->and($session->trip->user_id)->toBe($user->id);

    $this->get("/explore/{$session->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('explore/show')
            ->where('session.data.id', $session->id)
            ->where('session.data.status', 'active')
            ->has('opportunities.data', 0));      // E5 fills this; the empty state is honest

    // Resuming: the index now offers the open session.
    $this->get('/explore')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('activeSession.data.id', $session->id));

    $this->post("/explore/{$session->id}/end")->assertRedirect("/explore/{$session->id}");

    expect($session->fresh()->status)->toBe(ExploreSessionStatus::Ended);
});

it('renders the trips list and a trip over the web surface', function () {
    $this->actingAs($user = User::factory()->create());

    $trip = Trip::factory()->create(['user_id' => $user->id, 'name' => 'Stockholm test']);
    ExploreSession::factory()->create(['trip_id' => $trip->id, 'user_id' => $user->id]);

    $this->get('/trips')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('trips/index')
            ->has('trips.data', 1)
            ->where('trips.data.0.name', 'Stockholm test'));

    $this->get("/trips/{$trip->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('trips/show')
            ->where('trip.data.explore_sessions_count', 1)
            ->has('trip.data.explore_sessions', 1));

    $this->patch("/trips/{$trip->id}", ['name' => 'Renamed'])->assertRedirect("/trips/{$trip->id}");

    expect($trip->fresh()->name)->toBe('Renamed');
});

it('does not let the web surface open another user\'s session either', function () {
    $owner = User::factory()->create();
    $session = ExploreSession::factory()->create([
        'trip_id' => Trip::factory()->create(['user_id' => $owner->id]),
        'user_id' => $owner->id,
    ]);

    $this->actingAs(User::factory()->create());

    $this->get("/explore/{$session->id}")->assertForbidden();
    $this->post("/explore/{$session->id}/end")->assertForbidden();
    $this->get("/trips/{$session->trip_id}")->assertForbidden();
});

it('requires authentication for the explore pages', function () {
    $this->get('/explore')->assertRedirect('/login');
});
