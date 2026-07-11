<?php

declare(strict_types=1);

use App\Domain\Places\Enums\PlaceType;
use App\Domain\Places\Enums\PlaceTypeDomain;
use App\Enums\AppealFacet;

/*
|--------------------------------------------------------------------------
| Taxonomy invariants — docs/TAXONOMY.md
|--------------------------------------------------------------------------
|
| The Type axis and facet priors are data the whole scoring system leans on.
| These invariants catch a case added without its methods wired.
|
*/

it('gives every place type a domain, a positive dwell, and valid base facets', function () {
    foreach (PlaceType::cases() as $type) {
        expect($type->domain())->toBeInstanceOf(PlaceTypeDomain::class);
        expect($type->typicalDwellMinutes())->toBeGreaterThan(0);

        foreach ($type->baseFacets() as $facet) {
            expect($facet)->toBeInstanceOf(AppealFacet::class);
        }
    }
});

it('covers every domain with at least one leaf type', function () {
    $covered = array_unique(array_map(
        fn (PlaceType $type): string => $type->domain()->value,
        PlaceType::cases(),
    ));

    expect($covered)->toHaveCount(count(PlaceTypeDomain::cases()));
});

it('gives every non-practical place type at least one base facet', function () {
    foreach (PlaceType::cases() as $type) {
        if ($type->domain() === PlaceTypeDomain::Practical) {
            continue;
        }

        expect($type->baseFacets())->not->toBeEmpty("{$type->value} has no base facets");
    }
});

it('keeps the facet set at the designed fourteen', function () {
    expect(AppealFacet::cases())->toHaveCount(14);
});
