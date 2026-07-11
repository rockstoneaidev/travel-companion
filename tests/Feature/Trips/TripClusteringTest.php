<?php

declare(strict_types=1);

use App\Domain\Places\Data\Coordinates;
use App\Domain\Trips\Actions\StartExploreSession;
use App\Domain\Trips\Data\NewExploreSessionData;
use App\Domain\Trips\Enums\TravelMode;
use App\Domain\Trips\Enums\TripStatus;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| The implicit trip clustering (PRD §6.6) — tested at the Action, not over HTTP
|--------------------------------------------------------------------------
*/

function startSessionAt(User $user, float $lat, float $lng, ?CarbonImmutable $at = null): Trip
{
    $session = app(StartExploreSession::class)(new NewExploreSessionData(
        userId: $user->id,
        origin: new Coordinates($lat, $lng),
        timeBudgetMinutes: 180,
        travelMode: TravelMode::Walk,
        startedAt: $at,
    ));

    return $session->trip;
}

it('attaches a second session to the same trip when it is same-region and within the gap', function () {
    $user = User::factory()->create();

    $first = startSessionAt($user, 59.3100, 18.0200, CarbonImmutable::parse('2026-07-13 10:00:00'));
    $second = startSessionAt($user, 59.3400, 18.0700, CarbonImmutable::parse('2026-07-16 09:00:00')); // 3 days later, ~4 km

    expect($second->id)->toBe($first->id);
    expect(Trip::query()->count())->toBe(1);
    expect($second->fresh()->last_session_at->toDateString())->toBe('2026-07-16');
});

it('opens a new trip when the gap is too long', function () {
    $user = User::factory()->create();

    $first = startSessionAt($user, 59.3100, 18.0200, CarbonImmutable::parse('2026-07-13 10:00:00'));
    $second = startSessionAt($user, 59.3100, 18.0200, CarbonImmutable::parse('2026-07-20 10:00:00')); // 7 days later

    expect($second->id)->not->toBe($first->id);
    expect($first->fresh()->status)->toBe(TripStatus::Completed);
    expect($second->status)->toBe(TripStatus::Active);
});

it('opens a new trip when the region is different', function () {
    $user = User::factory()->create();

    $stockholm = startSessionAt($user, 59.3100, 18.0200, CarbonImmutable::parse('2026-07-13 10:00:00'));
    $burgundy = startSessionAt($user, 47.0240, 4.8390, CarbonImmutable::parse('2026-07-13 18:00:00')); // same day, 1500 km

    expect($burgundy->id)->not->toBe($stockholm->id);
    expect($stockholm->fresh()->status)->toBe(TripStatus::Completed);
});

it('keeps at most one active trip per user, in the database as well as the domain', function () {
    $user = User::factory()->create();

    startSessionAt($user, 59.3100, 18.0200, CarbonImmutable::parse('2026-07-13 10:00:00'));
    startSessionAt($user, 47.0240, 4.8390, CarbonImmutable::parse('2026-07-20 10:00:00'));
    startSessionAt($user, 48.8566, 2.3522, CarbonImmutable::parse('2026-08-01 10:00:00'));

    expect(Trip::query()->where('user_id', $user->id)->where('status', TripStatus::Active)->count())->toBe(1);
    expect(Trip::query()->where('user_id', $user->id)->count())->toBe(3);

    // The partial unique index is the real guard — the domain is not the only writer.
    expect(fn () => Trip::factory()->create(['user_id' => $user->id, 'status' => TripStatus::Active]))
        ->toThrow(QueryException::class);
});

it('does not cluster one user\'s session into another user\'s trip', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $aliceTrip = startSessionAt($alice, 59.3100, 18.0200);
    $bobTrip = startSessionAt($bob, 59.3101, 18.0201);   // same street corner, different person

    expect($bobTrip->id)->not->toBe($aliceTrip->id);
    expect($bobTrip->user_id)->toBe($bob->id);
});

it('does not absorb a session into a trip whose location history was erased', function () {
    $user = User::factory()->create();

    $first = startSessionAt($user, 59.3100, 18.0200);
    $first->forceFill(['anchor_point' => null])->save();

    $second = startSessionAt($user, 59.3100, 18.0200);

    expect($second->id)->not->toBe($first->id);
});
