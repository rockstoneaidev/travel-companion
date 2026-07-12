<?php

declare(strict_types=1);

use App\Domain\Opportunities\Data\SessionOpportunityData;
use App\Domain\Opportunities\Enums\OpportunityKind;
use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Opportunities\Services\UrgentSlot;
use App\Domain\Places\Data\Coordinates;
use App\Domain\Places\Data\PlaceData;
use App\Domain\Places\Enums\PlaceType;
use App\Domain\Places\Enums\PlaceTypeDomain;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| The GO NOW slot (SCREENS S1)
|--------------------------------------------------------------------------
|
| "The server guarantees at most one urgent item per feed." The client never
| decides which card is urgent and never re-sorts — so the guarantee has to be
| true here, not hoped for in the view.
|
*/

const NOW = '2026-07-14T12:00:00+00:00';

function slotItem(string $id, ?string $startsAt, ?string $endsAt): SessionOpportunityData
{
    return new SessionOpportunityData(
        id: $id,
        kind: $endsAt === null ? OpportunityKind::Evergreen : OpportunityKind::Event,
        status: OpportunityStatus::Scored,
        title: $id,
        summary: null,
        place: new PlaceData(
            id: $id,
            name: $id,
            coordinates: new Coordinates(59.31, 18.02),
            type: PlaceType::Square,
            typeDomain: PlaceTypeDomain::ArchitectureUrban,
            facets: [],
            source: 'osm',
        ),
        distanceMeters: 100,
        windowStartsAt: $startsAt === null ? null : CarbonImmutable::parse($startsAt),
        windowEndsAt: $endsAt === null ? null : CarbonImmutable::parse($endsAt),
        expiresAt: CarbonImmutable::parse(NOW)->addDay(),
    );
}

function applySlot(array $feed): array
{
    return new UrgentSlot(120)->apply($feed, CarbonImmutable::parse(NOW));
}

it('leaves an all-evergreen feed exactly as ranked', function () {
    $feed = applySlot([slotItem('a', null, null), slotItem('b', null, null)]);

    expect(array_column($feed, 'id'))->toBe(['a', 'b'])
        ->and(array_column($feed, 'urgent'))->toBe([false, false]);
});

it('promotes the one closing soonest to the top and marks only it urgent', function () {
    $feed = applySlot([
        slotItem('evergreen', null, null),
        slotItem('closes-in-90', '2026-07-14T09:00:00+00:00', '2026-07-14T13:30:00+00:00'),
        slotItem('closes-in-30', '2026-07-14T09:00:00+00:00', '2026-07-14T12:30:00+00:00'),
    ]);

    expect(array_column($feed, 'id'))->toBe(['closes-in-30', 'evergreen', 'closes-in-90'])
        ->and(array_column($feed, 'urgent'))->toBe([true, false, false]);
});

it('never marks two cards urgent, however many windows are closing', function () {
    $feed = applySlot([
        slotItem('one', '2026-07-14T11:00:00+00:00', '2026-07-14T12:20:00+00:00'),
        slotItem('two', '2026-07-14T11:00:00+00:00', '2026-07-14T12:40:00+00:00'),
        slotItem('three', '2026-07-14T11:00:00+00:00', '2026-07-14T13:00:00+00:00'),
    ]);

    expect(array_sum(array_map(fn ($i) => $i->urgent ? 1 : 0, $feed)))->toBe(1)
        ->and($feed[0]->id)->toBe('one');
});

it('does not shout GO NOW about a window that has not opened yet', function () {
    $feed = applySlot([slotItem('later-today', '2026-07-14T18:00:00+00:00', '2026-07-14T19:00:00+00:00')]);

    expect($feed[0]->urgent)->toBeFalse();
});

it('ignores a window that has already closed', function () {
    $feed = applySlot([slotItem('missed-it', '2026-07-14T08:00:00+00:00', '2026-07-14T11:00:00+00:00')]);

    expect($feed[0]->urgent)->toBeFalse();
});

it('ignores a window closing beyond the attention horizon', function () {
    // Open now, but not closing for six hours — that is not a "go now".
    $feed = applySlot([slotItem('all-evening', '2026-07-14T09:00:00+00:00', '2026-07-14T18:00:00+00:00')]);

    expect($feed[0]->urgent)->toBeFalse();
});
