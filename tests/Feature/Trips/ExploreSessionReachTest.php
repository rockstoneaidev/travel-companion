<?php

declare(strict_types=1);

use App\Domain\Places\Data\Coordinates;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Enums\ExploreSessionStatus;
use App\Domain\Trips\Enums\TravelMode;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| The Stage-A travel-time estimator, run backwards (PRD §10, conventions/12)
|--------------------------------------------------------------------------
*/

function sessionData(TravelMode $mode, int $minutes): ExploreSessionData
{
    return new ExploreSessionData(
        id: 'sess',
        tripId: 'trip',
        userId: 1,
        origin: new Coordinates(59.31, 18.02),
        timeBudgetMinutes: $minutes,
        travelMode: $mode,
        heading: null,
        destinationPoint: null,
        status: ExploreSessionStatus::Active,
        startedAt: CarbonImmutable::now(),
        expiresAt: CarbonImmutable::now()->addMinutes($minutes),
        endedAt: null,
    );
}

it('derives reach from the mode speed and the time budget', function (TravelMode $mode, int $minutes, int $expected) {
    expect(sessionData($mode, $minutes)->reachMeters())->toBe($expected);
})->with([
    // half the budget outbound, at effectiveSpeedKmh, ÷ pathFactor
    'walk 3h' => [TravelMode::Walk, 180, 5192],
    'walk 1h' => [TravelMode::Walk, 60, 1731],
    'bike 3h' => [TravelMode::Bike, 180, 16154],
    'drive 3h' => [TravelMode::Drive, 180, 44444],
]);

it('caps reach so a long drive cannot ask Postgres for half a continent', function () {
    expect(sessionData(TravelMode::Drive, 720)->reachMeters())
        ->toBe((int) config('trips.session.max_reach_meters'));
});
