<?php

declare(strict_types=1);

use App\Domain\Places\Services\FetchCommonsImages;
use App\Domain\Places\Services\ResolveRegion;
use App\Domain\Sources\Data\IngestRegion;
use App\Domain\Sources\Services\RegionIngest;
use App\Domain\Sources\Services\SourceRegistry;
use App\Jobs\Ingest\BuildRegionWorldModelJob;
use App\Jobs\Ingest\IngestRegionBoxJob;
use Illuminate\Bus\PendingBatch;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| The build chain must survive a dead job (conventions/09)
|--------------------------------------------------------------------------
|
| handle() already treats a failed *source* as degraded-not-fatal — but that
| catch cannot save us from the job itself being killed. A worker timeout is
| delivered by killing the process, so no catch inside handle() ever runs and
| nothing dispatches the next phase.
|
| That is how Nice stalled: the OSM ingest was killed at 900s, `resolve` was
| never queued, places_core stayed empty, and the curation queue therefore had
| nothing to draft from — an hour of waiting on a chain that had already died.
|
*/

it('carries the chain to the next source when an ingest job is killed', function () {
    Queue::fake();

    // OSM is the source that times out. The region must still reach the sources
    // after it, and then resolve — three of four sources is a usable region.
    new BuildRegionWorldModelJob('nice', 'ingest', 'osm')
        ->failed(new TimeoutExceededException('killed at 900s'));

    Queue::assertPushed(BuildRegionWorldModelJob::class, function (BuildRegionWorldModelJob $job): bool {
        return $job->regionKey === 'nice' && ($job->phase === 'ingest' || $job->phase === 'resolve');
    });
});

it('moves a dead resolve on to photos rather than stranding the region', function () {
    Queue::fake();

    new BuildRegionWorldModelJob('nice', 'resolve')->failed(new TimeoutExceededException('killed'));

    Queue::assertPushed(
        BuildRegionWorldModelJob::class,
        fn (BuildRegionWorldModelJob $job): bool => $job->phase === 'photos' && $job->regionKey === 'nice',
    );
});

it('does not loop when the last phase dies — there is nowhere to go', function () {
    Queue::fake();

    new BuildRegionWorldModelJob('nice', 'warm')->failed(new TimeoutExceededException('killed'));

    // Every hop moves ONWARD, so a repeatedly-failing phase drains the chain
    // rather than re-queueing itself forever.
    Queue::assertNothingPushed();
});

it('cuts a big region into small boxes, one job each', function () {
    // Stockholm is 584 km². ONE job for that held its reservation past retry_after,
    // the queue decided it was dead, handed it to a second worker, and tries=1
    // killed it as MaxAttemptsExceeded — having done nothing wrong. An hour of
    // fetched elements died with it, unwritten.
    //
    // A job's timeout is capped by retry_after, so there is a HARD CEILING on how
    // long any job may run. Raising the timeout is a treadmill that ends at that
    // wall; making the jobs small ends it.
    $stockholm = IngestRegion::named('stockholm')->boxes();
    $nice = IngestRegion::named('nice')->boxes();

    expect($stockholm)->toHaveCount(45)
        ->and($nice)->toHaveCount(6);

    // The boxes tile the region exactly — no gap, no overlap at the seams.
    $region = IngestRegion::named('nice');
    expect(min(array_map(fn ($b) => $b->south, $nice)))->toBe($region->south)
        ->and(max(array_map(fn ($b) => $b->north, $nice)))->toBe($region->north)
        ->and(min(array_map(fn ($b) => $b->west, $nice)))->toBe($region->west)
        ->and(max(array_map(fn ($b) => $b->east, $nice)))->toBe($region->east);
});

it('queues every box up front instead of chaining them', function () {
    Bus::fake();

    new BuildRegionWorldModelJob('stockholm', 'ingest', 'osm')->handle(
        app(RegionIngest::class),
        app(SourceRegistry::class),
        app(ResolveRegion::class),
        app(FetchCommonsImages::class),
    );

    /*
     * BATCHED, NOT CHAINED — and they are not equivalent.
     *
     * Chaining (box 12 dispatches box 13) only advances if box 12's failure handler
     * actually runs. A SIGKILL, an OOM or a container restart cuts that thread and the
     * region strands in silence — the same class of bug as the one that killed
     * Stockholm. A batch has no thread to cut: the other 44 boxes never depended on
     * box 12 at all.
     *
     * They still run strictly in SEQUENCE — that is the supervisor's job
     * (maxProcesses 1, for Overpass's sake), not the chain's.
     */
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 45
        && $batch->jobs->every(fn ($job): bool => $job instanceof IngestRegionBoxJob)
        && $batch->options['allowFailures'] === true);
});

it('lets one dead box cost only that box', function () {
    Log::spy();

    // allowFailures() on the batch, and a failed() that logs rather than throws: 44 of
    // 45 boxes is a usable city, and abandoning the region to protect a few square
    // kilometres of it would be the wrong trade every time.
    new IngestRegionBoxJob('stockholm', 'osm', 12)->failed(new RuntimeException('Overpass had a bad day'));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'box 12 failed'));
});

it('reaches resolve even when the ingest orchestrator itself dies', function () {
    Queue::fake();

    // The region must not strand. A dead orchestrator carries the chain onward.
    new BuildRegionWorldModelJob('nice', 'ingest', 'osm')->failed(new TimeoutExceededException('killed'));

    Queue::assertPushed(BuildRegionWorldModelJob::class, fn (BuildRegionWorldModelJob $job): bool => $job->phase === 'ingest' || $job->phase === 'resolve');
});
