<?php

declare(strict_types=1);

use App\Domain\Opportunities\Data\SessionOpportunityData;
use App\Domain\Opportunities\Enums\OpportunityKind;
use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Places\Data\Coordinates;
use App\Domain\Places\Data\PlaceData;
use App\Domain\Places\Enums\PlaceType;
use App\Domain\Places\Enums\PlaceTypeDomain;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Promoting an item to GO NOW must not forget what the user did to it
|--------------------------------------------------------------------------
|
| `asUrgent()` is a hand-rolled copy constructor, and it did what hand-rolled
| copy constructors do: it silently dropped the field somebody added after it
| was written. `kept` fell back to its default of FALSE, so the moment an item
| was promoted to the GO NOW slot it forgot it had been kept — the card offered
| "Keep" again on the very next reload and the user's tap had gone nowhere they
| could see.
|
| It only broke in the EVENING, which is why it survived for so long: whether an
| item wins the urgent slot depends on its time window against the clock, so the
| bug was invisible to anyone who happened to look before five. The suite found it
| at 19:40 and had been green on the same commit at 12:00.
|
| So this test asserts the copy, not the clock. Every field survives promotion.
|
*/

function urgentCandidate(bool $kept): SessionOpportunityData
{
    return new SessionOpportunityData(
        id: 'opp-1',
        kind: OpportunityKind::cases()[0],
        status: OpportunityStatus::cases()[0],
        title: 'Skinnarviksberget',
        summary: 'The high rock over the water.',
        place: new PlaceData(
            id: 'place-1',
            name: 'Skinnarviksberget',
            coordinates: new Coordinates(59.317, 18.043),
            type: PlaceType::Viewpoint,
            typeDomain: PlaceTypeDomain::NatureLandscape,
            facets: ['scenic'],
            source: 'osm',
            distanceMeters: 400,
        ),
        distanceMeters: 400,
        windowStartsAt: CarbonImmutable::parse('2026-07-13 20:00'),
        windowEndsAt: CarbonImmutable::parse('2026-07-13 22:00'),
        expiresAt: CarbonImmutable::parse('2026-07-14 08:00'),
        recommendationId: 'rec-1',
        walkMinutes: 7,
        image: null,
        kept: $kept,
    );
}

it('carries kept through the GO NOW promotion', function () {
    // The whole bug, in one assertion: the user kept it, then it became urgent, and the
    // product forgot.
    $promoted = urgentCandidate(kept: true)->asUrgent();

    expect($promoted->urgent)->toBeTrue()
        ->and($promoted->kept)->toBeTrue();
});

it('does not invent a keep that never happened', function () {
    $promoted = urgentCandidate(kept: false)->asUrgent();

    expect($promoted->urgent)->toBeTrue()
        ->and($promoted->kept)->toBeFalse();
});

it('carries every other field through the promotion too', function () {
    // The failure mode is a field being dropped, so the test is a field-by-field
    // comparison rather than a spot-check of the one we happened to lose. The next field
    // added to this DTO is the next one that can go missing.
    $original = urgentCandidate(kept: true);
    $promoted = $original->asUrgent();

    foreach (get_object_vars($original) as $field => $value) {
        if ($field === 'urgent') {
            continue;   // the one field promotion is allowed to change
        }

        expect($promoted->{$field})->toEqual($value, "asUrgent() dropped `{$field}`");
    }
});
