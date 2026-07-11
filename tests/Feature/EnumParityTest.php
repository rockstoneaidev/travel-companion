<?php

declare(strict_types=1);

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Opportunities\Enums\OpportunityKind;
use App\Domain\Places\Enums\PlaceType;
use App\Domain\Places\Enums\PlaceTypeDomain;
use App\Domain\Trips\Enums\TravelMode;
use App\Enums\AppealFacet;
use App\Enums\Permission;
use App\Enums\Role;

/*
|--------------------------------------------------------------------------
| Enum parity — docs/conventions/02-enums.md
|--------------------------------------------------------------------------
|
| Every PHP enum that crosses the wire is mirrored as a const array in
| resources/js/types/enums.ts. This test is what keeps the two from drifting.
|
*/

$tsConstValues = function (string $constant): array {
    $source = file_get_contents(resource_path('js/types/enums.ts'));

    preg_match("/export const {$constant} = \\[(.*?)\\] as const/s", $source, $matches);

    expect($matches)->not->toBeEmpty("const {$constant} not found in enums.ts");

    return array_values(array_filter(array_map(
        fn (string $value): string => trim(trim($value), "'\""),
        explode(',', $matches[1]),
    )));
};

it('mirrors Role in resources/js/types/enums.ts', function () use ($tsConstValues) {
    expect($tsConstValues('ROLES'))->toBe(Role::values());
});

it('mirrors Permission in resources/js/types/enums.ts', function () use ($tsConstValues) {
    expect($tsConstValues('PERMISSIONS'))->toBe(Permission::values());
});

it('mirrors AppealFacet in resources/js/types/enums.ts', function () use ($tsConstValues) {
    expect($tsConstValues('APPEAL_FACETS'))->toBe(AppealFacet::values());
});

it('mirrors PlaceTypeDomain in resources/js/types/enums.ts', function () use ($tsConstValues) {
    expect($tsConstValues('PLACE_TYPE_DOMAINS'))->toBe(PlaceTypeDomain::values());
});

it('mirrors PlaceType in resources/js/types/enums.ts', function () use ($tsConstValues) {
    expect($tsConstValues('PLACE_TYPES'))->toBe(PlaceType::values());
});

it('mirrors OpportunityKind in resources/js/types/enums.ts', function () use ($tsConstValues) {
    expect($tsConstValues('OPPORTUNITY_KINDS'))->toBe(OpportunityKind::values());
});

it('mirrors TravelMode in resources/js/types/enums.ts', function () use ($tsConstValues) {
    expect($tsConstValues('TRAVEL_MODES'))->toBe(TravelMode::values());
});

it('mirrors FeedbackEvent in resources/js/types/enums.ts', function () use ($tsConstValues) {
    expect($tsConstValues('FEEDBACK_EVENTS'))->toBe(FeedbackEvent::values());
});
