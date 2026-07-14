<?php

declare(strict_types=1);

use App\Domain\Places\Data\Coordinates;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Enums\ExploreSessionStatus;
use App\Domain\Trips\Enums\TravelMode;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| E35 — the cone turns with the traveller
|--------------------------------------------------------------------------
|
| A re-anchor knows two positions and the order they happened in. That is a heading,
| and it is a MEASURED one — the only heading in the system that isn't a guess or a
| declaration. Not using it means a session that started pointing north searches north
| forever, and the driver who took the westbound exit gets a feed aimed at the road
| they didn't take.
|
*/

function sessionAimed(?int $heading, ?Coordinates $destination = null): ExploreSessionData
{
    return new ExploreSessionData(
        id: 'session-1',
        tripId: 'trip-1',
        userId: 1,
        origin: new Coordinates(59.3293, 18.0686),   // Stockholm
        timeBudgetMinutes: 180,
        travelMode: TravelMode::Drive,
        heading: $heading,
        destinationPoint: $destination,
        status: ExploreSessionStatus::Active,
        startedAt: CarbonImmutable::now(),
        expiresAt: CarbonImmutable::now()->addHours(3),
        endedAt: null,
    );
}

it('measures the heading from the move, replacing whatever the session was aimed at before', function () {
    // Due EAST: longitude up at constant latitude → bearing ≈ 90°.
    $east = sessionAimed(heading: 270)->reAnchoredAt(new Coordinates(59.3293, 18.2686));
    expect($east->heading)->toBeGreaterThan(85)->toBeLessThan(95);

    // Due SOUTH → ≈ 180°. The session was pointing north; it is now pointing south,
    // because that is where the person went. Nothing else in the pipeline needs to
    // know that movement exists — coverage just reads `heading`.
    $south = sessionAimed(heading: 0)->reAnchoredAt(new Coordinates(59.1293, 18.0686));
    expect($south->heading)->toBeGreaterThan(175)->toBeLessThan(185);

    // Due WEST → ≈ 270°.
    $west = sessionAimed(heading: 90)->reAnchoredAt(new Coordinates(59.3293, 17.8686));
    expect($west->heading)->toBeGreaterThan(265)->toBeLessThan(275);
});

it('leaves a declared destination alone — the corridor already knows where you are going', function () {
    $session = sessionAimed(heading: 45, destination: new Coordinates(59.1955, 17.6253));

    // Drive a leg due east. The corridor still points at Södertälje, and it should: a
    // heading inferred from one bend in the road would flap, and coverage would follow
    // it away from the destination the person actually named.
    $moved = $session->reAnchoredAt(new Coordinates(59.3293, 18.2686));

    expect($moved->heading)->toBe(45)
        ->and($moved->destinationPoint?->lat)->toBe(59.1955);
});

it('keeps its old aim when the re-anchor lands on the same spot', function () {
    $session = sessionAimed(heading: 123);

    // Degenerate input: a bearing from a point to itself is undefined, and atan2(0, 0)
    // would cheerfully return 0 — silently re-aiming the whole session due north.
    expect($session->reAnchoredAt(new Coordinates(59.3293, 18.0686))->heading)->toBe(123);
});

it('gives a session with no heading at all one, the moment it learns which way you went', function () {
    // The common case, and the reason this is worth doing: a session starts as a disc
    // because nobody knew a direction. One move later, we know.
    $moved = sessionAimed(heading: null)->reAnchoredAt(new Coordinates(59.3293, 18.2686));

    expect($moved->heading)->not->toBeNull()
        ->toBeGreaterThan(85)->toBeLessThan(95);
});
