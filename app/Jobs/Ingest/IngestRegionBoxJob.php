<?php

declare(strict_types=1);

namespace App\Jobs\Ingest;

use App\Domain\Sources\Data\IngestRegion;
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
     * Two, now that a job is a minute rather than an hour.
     *
     * A box that catches Overpass on a bad breath deserves a second ask before we
     * write off a few square kilometres of a city. This was only unsafe while jobs
     * were long enough to be re-reserved mid-flight.
     */
    public int $tries = 2;

    public function __construct(
        public readonly string $regionKey,
        public readonly string $source,
        public readonly int $box,
    ) {
        $this->onQueue(QueueLane::Ingest->value);
        $this->onConnection(QueueLane::Ingest->connection());
    }

    public function handle(RegionIngest $ingest): void
    {
        // The batch was cancelled (the admin pressed stop, or the region was rebuilt
        // under us). Do not spend an Overpass slot on an answer nobody wants.
        if ($this->batch()?->cancelled() === true) {
            return;
        }

        $region = IngestRegion::named($this->regionKey);
        $boxes = $region->boxes();

        if (! isset($boxes[$this->box])) {
            return;   // the region's grid changed between dispatch and run
        }

        $result = $ingest->ingest($region, $this->source, $boxes[$this->box]);

        Log::info("world-model ingest {$this->regionKey}/{$this->source}", [
            ...$result,
            'box' => $this->box + 1,
            'of' => count($boxes),
        ]);
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
