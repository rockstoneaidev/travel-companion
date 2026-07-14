<?php

declare(strict_types=1);

namespace App\Jobs\Ingest;

use App\Domain\Sources\Exceptions\OverpassRateLimited;
use App\Domain\Sources\Services\RegionBuildStatus;
use App\Domain\Sources\Services\RegionCatalog;
use App\Domain\Sources\Services\RegionIngest;
use App\Enums\QueueLane;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One grid cell of one source of one region (IngestRegion::boxes()).
 *
 * ===========================================================================
 *  WHY THIS JOB IS SMALL, AND WHY THAT IS THE WHOLE POINT
 * ===========================================================================
 *
 * A job's `timeout` is capped by the queue's `retry_after`, which is itself capped
 * by how long you are willing to leave a genuinely dead job undetected. So there is
 * a HARD CEILING on how long any job may run, and "make the timeout bigger" is a
 * treadmill that ends at that wall.
 *
 * Stockholm hit it. One job fetching 584 km² of Overpass held its reservation past
 * retry_after; the queue concluded it was dead, handed it to a second worker, and
 * `tries = 1` failed it as MaxAttemptsExceeded — having done nothing wrong. An hour
 * of successfully fetched elements died with it, unwritten, because the old ingest
 * buffered the whole region in memory and persisted only at the end.
 *
 * So a box is the unit of WORK *and* the unit of PERSISTENCE: one Overpass query,
 * one upsert, roughly a minute. What it fetched is on disk before it returns, and a
 * box that dies costs a box.
 *
 * BATCHED, NOT CHAINED. The boxes are queued together up front rather than each
 * dispatching the next. Chaining looks equivalent and is not: box 12 → box 13 only
 * happens if box 12's failure handler actually runs, so a SIGKILL, an OOM or a
 * container restart strands the region silently — the same class of bug we are
 * fixing. Queued as a batch, the other 44 boxes never depended on box 12 at all.
 *
 * They still run STRICTLY IN SEQUENCE: the ingest supervisor is maxProcesses 1
 * (public Overpass allows ~2 slots per IP, and running the corridor cities back to
 * back is what cost Nantes its whole OSM layer). Sequence is enforced by the worker,
 * not by fragile chaining — which is exactly where that decision belongs.
 */
final class IngestRegionBoxJob implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Comfortably clear of the ceiling. The arithmetic that must hold — and is
     * asserted in tests/Feature/QueueConfigTest.php:
     *
     *   OsmAdapter::BUDGET_SECONDS + HTTP_TIMEOUT_SECONDS  <  $timeout  <  retry_after
     *            300               +        150            <    600     <    1800
     *
     * The left-hand side is the true worst case: the budget refuses to *start* a
     * request that could outlive it, so the last one may begin at second 299 and run
     * its full 150 s. Everything after that is a bulk upsert of a few hundred rows.
     */
    public int $timeout = 600;

    /**
     * Five, now that a job is a minute rather than an hour.
     *
     * A box that catches Overpass on a bad breath deserves more than one ask before
     * we write off a few square kilometres of a city — and the commonest reason to
     * retry is a 429, which is transient by definition. Retrying at all was only
     * unsafe while jobs were long enough to be re-reserved mid-flight.
     */
    public int $tries = 5;

    /**
     * Backoff between attempts, in seconds.
     *
     * Long, and deliberately so: the failure we are usually retrying is Overpass
     * asking us to slow down (429), and retrying that in ten seconds is not slowing
     * down. A box that waits ten minutes and then succeeds is worth far more than a
     * box we gave up on — losing one is losing a few square kilometres of a city
     * permanently, which is how Lyon ended up with nine OSM items.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 180, 420, 900];
    }

    public function __construct(
        public readonly string $regionKey,
        public readonly string $source,
        public readonly int $box,
        /*
         * The pin, carried so this job resolves the SAME box list the batch was built
         * from (E48).
         *
         * `$box` is an INDEX, and an index means nothing without the ordering it indexes
         * into. The batch is built nearest-first when a region is being learned on
         * demand; if this job re-derived the plain grid order, index 0 would be the
         * south-west corner instead of the ground under the user's feet — and every box
         * would silently fetch the wrong bbox. The ordering must travel with the index or
         * the index is a lie.
         */
        public readonly ?float $nearLat = null,
        public readonly ?float $nearLng = null,
    ) {
        $this->onQueue(QueueLane::Ingest->value);
        $this->onConnection(QueueLane::Ingest->connection());
    }

    /**
     * The pin, or null — SAFE ACROSS A DEPLOY.
     *
     * `isset()`, not a direct read, and the difference is a production outage. A queued
     * job is unserialized WITHOUT its constructor running, so a job that was already on
     * the queue when this property was added has no value for it — not null, *uninitialized*
     * — and touching a typed property in that state is a fatal Error, not a null.
     *
     * It happened here, immediately: fifty-one Overpass boxes queued by the previous
     * build died on "must not be accessed before initialization" the moment the new code
     * shipped. In production that is a rolling deploy quietly killing every in-flight job.
     *
     * `isset()` on an uninitialized typed property is false, and never throws. An old job
     * therefore falls back to plain grid order — which is exactly what it was dispatched
     * expecting.
     *
     * @return array{0: float, 1: float}|null
     */
    private function pin(): ?array
    {
        if (! isset($this->nearLat, $this->nearLng)) {
            return null;
        }

        return [$this->nearLat, $this->nearLng];
    }

    public function handle(RegionIngest $ingest, RegionBuildStatus $status): void
    {
        // The batch was cancelled (the admin pressed stop, or the region was rebuilt
        // under us). Do not spend an Overpass slot on an answer nobody wants.
        if ($this->batch()?->cancelled() === true) {
            return;
        }

        /*
         * A sign of life. The OSM ingest is 45 boxes on ONE worker — three quarters of
         * an hour in which the PHASE never changes — so without a heartbeat from the
         * boxes themselves a perfectly healthy build is indistinguishable from a dead
         * one, and the console would offer to restart it half way through.
         */
        $status->heartbeat($this->regionKey);

        $region = app(RegionCatalog::class)->named($this->regionKey);
        $pin = $this->pin();
        $boxes = $pin !== null ? $region->boxesNearest($pin[0], $pin[1]) : $region->boxes();

        if (! isset($boxes[$this->box])) {
            return;   // the region's grid changed between dispatch and run
        }

        try {
            $result = $ingest->ingest($region, $this->source, $boxes[$this->box]);
        } catch (OverpassRateLimited $e) {
            /*
             * "Come back later" is not "this box is broken".
             *
             * Put it back on the queue with a real backoff rather than writing the box
             * off. Rate limiting is transient; a lost box is not — it is a hole in the
             * city that nothing will ever fill, and nobody will notice, because the
             * region will simply have fewer places in it than it should.
             */
            Log::info("world-model ingest {$this->regionKey}/{$this->source} box {$this->box}: rate limited, will retry", [
                'attempt' => $this->attempts(),
            ]);

            $this->release($this->backoff()[$this->attempts() - 1] ?? 900);

            return;
        }

        Log::info("world-model ingest {$this->regionKey}/{$this->source}", [
            ...$result,
            'box' => $this->box + 1,
            'of' => count($boxes),
        ]);

        /*
         * Turn what we just fetched into PLACES, now, rather than in two hours (E48).
         *
         * A box writes source items; only `resolve` makes them servable. Waiting for the
         * whole batch means the person who walked into this region — and whose feet these
         * first, nearest boxes were chosen for — sees nothing at all until the entire city
         * is finished. Unique per region, so the other fifty-four boxes do not each queue
         * their own.
         */
        BuildRegionWorldModelJob::dispatch($this->regionKey, 'resolve-progressive');
    }

    /**
     * A failed box is degraded, never fatal (conventions/09).
     *
     * The batch is dispatched with allowFailures(), so this does not stop the other
     * 44 — 44 of 45 boxes is a usable city, and abandoning it over one would be
     * throwing away the region to protect a few square kilometres of it.
     */
    public function failed(\Throwable $e): void
    {
        Log::warning("world-model ingest {$this->regionKey}/{$this->source} box {$this->box} failed", [
            'error' => $e->getMessage(),
        ]);
    }
}
