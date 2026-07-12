<?php

declare(strict_types=1);

use App\Jobs\Ingest\BuildRegionWorldModelJob;
use Illuminate\Queue\TimeoutExceededException;
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
