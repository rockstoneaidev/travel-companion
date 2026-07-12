<?php

declare(strict_types=1);

use App\Domain\Sources\Services\RegionBuildStatus;

/*
|--------------------------------------------------------------------------
| A claim must not outlive the work (ADMIN.md)
|--------------------------------------------------------------------------
|
| Staging showed "building · evidence" with the button greyed out FOR HOURS, for a
| build that was already dead. `finish()` — the only thing that released the claim —
| ran on success; a worker killed at its timeout never reaches its failure handler,
| and the last phase had nowhere to chain onward to. So the claim sat there until a
| six-hour TTL expired. Six hours of a disabled button is not "safe", it is broken.
|
| A build now has to PROVE IT IS ALIVE.
|
*/

it('treats a build with no sign of life as dead, and hands the button back', function () {
    $status = app(RegionBuildStatus::class);

    expect($status->start('nice'))->toBeTrue()
        ->and($status->current('nice')['stalled'])->toBeFalse()
        ->and($status->start('nice'))->toBeFalse();   // a LIVE build still locks the button

    // Sixteen minutes of silence. The longest legitimate silence is one grid box —
    // one Overpass query, budget 300s plus a 150s HTTP timeout — so this is dead.
    $this->travel(16)->minutes();

    expect($status->current('nice')['stalled'])->toBeTrue()
        ->and($status->isBuilding('nice'))->toBeFalse()
        ->and($status->start('nice'))->toBeTrue();   // pressable again; every phase is idempotent
});

it('keeps a long, healthy ingest alive on the boxes alone', function () {
    $status = app(RegionBuildStatus::class);

    $status->start('stockholm');
    $status->phase('stockholm', 'ingest');

    /*
     * Stockholm is 45 boxes on ONE worker: three quarters of an hour in which the PHASE
     * never changes. Without the boxes themselves reporting in, a perfectly healthy
     * build would look exactly like a dead one and we would offer to restart it half
     * way through — which is worse than the bug we are fixing.
     */
    foreach (range(1, 5) as $box) {
        $this->travel(10)->minutes();
        $status->heartbeat('stockholm');

        expect($status->current('stockholm')['stalled'])->toBeFalse("box {$box} should have kept it alive");
    }

    expect($status->isBuilding('stockholm'))->toBeTrue()
        ->and($status->current('stockholm')['phase'])->toBe('ingest');   // heartbeat never rewrites the phase
});

it('ignores a heartbeat from a box nobody is claiming — a stray box is not a build', function () {
    $status = app(RegionBuildStatus::class);

    $status->heartbeat('lyon');

    expect($status->current('lyon'))->toBeNull();
});
