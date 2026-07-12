<?php

declare(strict_types=1);

use App\Domain\Trips\Enums\ExploreSessionStatus;
use App\Domain\Trips\Enums\TripSource;
use App\Domain\Trips\Enums\TripStatus;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| The planner path on the web (PRD §6.6)
|--------------------------------------------------------------------------
|
| CreateTrip was written, tested, exposed on /api/v1 — and had no door on the web.
| The Trips screen said "you never create one", which is true of the IMPLICIT path
| (a trip appears when you start exploring) and false of the product: nobody plans
| the trip they are already on, and the France trip is the one trip anybody plans.
|
| The invariant these tests defend is the one the API guards too: a planned trip
| opens as `planned`, NEVER as `active`. "Active" begins at the first session, and
| that is the implicit clustering's decision — a client that could POST straight to
| active would break the one-active-trip-per-user index.
|
*/

it('plans a trip from the web, and it opens planned — never active', function () {
    $user = profilingAsked(User::factory()->create());

    $this->actingAs($user)
        ->post('/trips', ['name' => 'France, late July'])
        ->assertRedirect();

    $trip = Trip::query()->sole();

    expect($trip->name)->toBe('France, late July')
        ->and($trip->status)->toBe(TripStatus::Planned)   // not Active
        ->and($trip->source)->toBe(TripSource::User)
        ->and($trip->user_id)->toBe($user->id);
});

it('accepts an optional anchor point, and works without one', function () {
    $user = profilingAsked(User::factory()->create());

    $this->actingAs($user)
        ->post('/trips', ['name' => 'Nice', 'anchor_point' => ['lat' => 43.6961, 'lng' => 7.2712]])
        ->assertRedirect();

    expect(Trip::query()->sole()->anchor_point)->not->toBeNull();
});

it('refuses a nameless trip — "Untitled trip" is not a name, it is an apology', function () {
    $this->actingAs(profilingAsked(User::factory()->create()))
        ->post('/trips', ['name' => ''])
        ->assertSessionHasErrors('name');

    expect(Trip::query()->count())->toBe(0);
});

it('will not let a client plan a trip straight into active', function () {
    $this->actingAs(profilingAsked(User::factory()->create()))
        ->post('/trips', ['name' => 'Sneaky', 'status' => 'active'])
        ->assertRedirect();

    // `status` is not in the FormRequest's rules and never reaches the action: the
    // one-active-trip-per-user invariant belongs to the clustering, not to a payload.
    expect(Trip::query()->sole()->status)->toBe(TripStatus::Planned);
});

/*
| A person is in one place at a time.
|
| Two sessions once opened a minute apart and both sat "active", because the start
| form was submitted twice and nothing stopped it. The app assumes there is exactly
| one — the dashboard resumes the LATEST active session — so the older one became
| invisible while remaining real: still expiring, still costing scouting, unreachable
| from any screen.
*/
it('closes whatever session was open when a new one starts', function () {
    $user = profilingAsked(User::factory()->create());

    $this->actingAs($user)->post('/explore', [
        'origin' => ['lat' => 59.3293, 'lng' => 18.0686],
        'time_budget_minutes' => 180,
        'travel_mode' => 'walk',
    ])->assertRedirect();

    $first = ExploreSession::query()->sole();

    $this->actingAs($user)->post('/explore', [
        'origin' => ['lat' => 59.3103, 'lng' => 18.0227],
        'time_budget_minutes' => 90,
        'travel_mode' => 'bike',
    ])->assertRedirect();

    expect($first->fresh()->status)->toBe(ExploreSessionStatus::Ended)
        ->and($first->fresh()->ended_at)->not->toBeNull();

    // Exactly one active session, always.
    expect(ExploreSession::query()
        ->where('status', ExploreSessionStatus::Active)
        ->count())->toBe(1);
});
