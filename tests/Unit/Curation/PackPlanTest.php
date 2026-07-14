<?php

declare(strict_types=1);

use App\Domain\Curation\Data\PackPlan;

/*
|--------------------------------------------------------------------------
| One plan, in one place, because three copies of it disagreed
|--------------------------------------------------------------------------
|
| CURATION §4's targets are nights-weighted and argued. They lived as a
| `const TARGETS` on a CONSOLE COMMAND, and everything that needed them reached
| in and took them — including an HTTP controller and a domain query, both of
| which ended up importing a console command to find out how big a pack should be.
|
| `curation:publish` did not reach in at all. It carried its own flat
| TARGET_APPROVED = 25 for every region, and so refused to publish Bordeaux (23
| approved, target 20) and Lyon (20 approved, target 20) — two packs that had MET
| their target and were finished. The plan said one thing and the gate said
| another, and the gate won.
|
| A number that decides whether work ships is a domain fact.
|
*/

it('knows what each region is aiming for', function () {
    // The §4 plan, verbatim. If this table changes, the doc changed — or someone
    // changed the plan without reading it.
    expect(PackPlan::targetFor('paris'))->toBe(40)      // two stays, deepest pack
        ->and(PackPlan::targetFor('nice'))->toBe(30)
        ->and(PackPlan::targetFor('nantes'))->toBe(30)
        ->and(PackPlan::targetFor('dijon'))->toBe(25)
        ->and(PackPlan::targetFor('lyon'))->toBe(20)
        ->and(PackPlan::targetFor('bordeaux'))->toBe(20)
        ->and(PackPlan::targetFor('toulouse'))->toBe(20)
        ->and(PackPlan::targetFor('stockholm'))->toBe(30);
});

it('does not let an unplanned region set its own bar', function () {
    // 20 is the floor the plan uses for its shallowest city. A region nobody has planned
    // a pack for does not get to decide that zero is enough.
    expect(PackPlan::targetFor('reykjavik'))->toBe(20);
});

it('would have published Bordeaux and Lyon, which the flat 25 refused', function () {
    // The regression, stated as the numbers that actually occurred.
    expect(23)->toBeGreaterThanOrEqual(PackPlan::targetFor('bordeaux'))
        ->and(20)->toBeGreaterThanOrEqual(PackPlan::targetFor('lyon'));
});
