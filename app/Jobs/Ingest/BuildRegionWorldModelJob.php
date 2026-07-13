<?php

declare(strict_types=1);

namespace App\Jobs\Ingest;

use App\Domain\Places\Services\FetchCommonsImages;
use App\Domain\Places\Services\FetchWikipediaExtracts;
use App\Domain\Places\Services\ResolveRegion;
use App\Domain\Sources\Data\IngestRegion;
use App\Domain\Sources\Services\RegionBuildStatus;
use App\Domain\Sources\Services\RegionIngest;
use App\Domain\Sources\Services\SourceRegistry;
use App\Enums\QueueLane;
use App\Jobs\Scouts\WarmTileJob;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The admin console's "build world model" (thin wrapper — conventions/08):
 * phase `ingest` runs each open-core source (a failed source is degraded,
 * never fatal — conventions/09), then self-chains into `resolve`, which works
 * through tiles in small batches and re-dispatches itself until none remain —
 * each hop stays well inside the queue's retry_after window, and resolver
 * idempotency makes every re-entry safe. Then `photos`, then `warm`, which
 * pre-fills the shared tile cache so the first real session does not pay for a
 * cold tile on the read path.
 */
final class BuildRegionWorldModelJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    private const RESOLVE_BATCH_TILES = 12;

    /**
     * The ORCHESTRATOR is short by construction: it queues work, it does not do it.
     * The long part (Overpass) lives in IngestRegionBoxJob, one small box at a time.
     *
     * Must still stay below `redis-long`'s retry_after — enforced by
     * tests/Feature/QueueConfigTest.php.
     */
    public int $timeout = 600;

    public int $tries = 1;

    /**
     * BOXED SOURCES — the ones we cut the region up for.
     *
     * Overpass answers a bbox and its cost is a function of how much is inside it, so
     * it was boxed from the start.
     *
     * WIKIDATA JOINED IT, and the reason is memory, not time. The unboxed sources ran
     * the whole region in one process: Wikidata decoded a 25,000-row SPARQL response
     * into PHP arrays in a single `->json()` call, and RegionIngest then built four more
     * arrays on top. On a 3.7 GB box with ~600 MB free that killed the app container
     * mid-Paris — and since Horizon runs *inside* that container, it took the serving
     * site down with it. The `MaxAttemptsExceeded` failures that had been piling up for
     * a day were the same event seen from the queue's side: the worker was killed, the
     * job was re-reserved, `tries = 1` wrote it off.
     *
     * It could NOT be fixed by offset-paging, which is the obvious move: Wikidata's
     * normalize groups binding rows per item to recover the P31 class list, so an item
     * split across two pages gets typed from half its classes. Boxing gives a page that
     * is semantically whole — a bbox answer is complete for its bbox — which is the
     * property that makes chunking safe here.
     *
     * DATAtourisme and Mérimée stay UNBOXED, and stream instead ({@see PagedScoutSource}):
     * they are cursor/offset APIs where 45 small bbox queries would be slower and ruder
     * than one paged walk, and their normalize is per-row, so a page is already whole.
     */
    private const BOXED_SOURCES = ['osm', 'wikidata'];

    public function __construct(
        public readonly string $regionKey,
        public readonly string $phase = 'ingest',
        public readonly ?string $source = null,
    ) {
        $this->onQueue(QueueLane::Ingest->value);
        $this->onConnection(QueueLane::Ingest->connection());
    }

    public function uniqueId(): string
    {
        return implode(':', array_filter([$this->regionKey, $this->phase, $this->source]));
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(
        RegionIngest $ingest,
        SourceRegistry $registry,
        ResolveRegion $resolve,
        FetchCommonsImages $photos,
        RegionBuildStatus $status,
        FetchWikipediaExtracts $wikipedia,
    ): void {
        $region = IngestRegion::named($this->regionKey);

        // So the admin console can say what is happening instead of nothing at all.
        $status->phase($this->regionKey, $this->source === null ? $this->phase : "{$this->phase}: {$this->source}");

        if ($this->phase === 'ingest') {
            // ONE SOURCE PER JOB, chained.
            //
            // This used to run every source in a single job — Mérimée, then
            // DATAtourisme, then Wikidata, then OSM with its adaptive splits and
            // politeness sleeps. For a real city that is many minutes in one job,
            // and a job that long is a job the queue starts guessing about.
            //
            // A failed source is still degraded, never fatal (conventions/09):
            // the chain moves to the next source regardless, because a region with
            // three of four sources is a usable region and a region with none is not.
            $source = $this->source ?? $registry->keys()[0];

            /*
             * ONE BOX PER JOB (IngestRegion::boxes()).
             *
             * A job's timeout is capped by the queue's retry_after, so there is a hard
             * ceiling on how long ANY job may run — and Stockholm walked straight into
             * it: one job fetching 584 km² of Overpass held its reservation past
             * retry_after, the queue decided it was dead, handed it to a second worker,
             * and tries=1 killed it as MaxAttemptsExceeded having done nothing wrong.
             * An hour of fetched elements died with it, unwritten.
             *
             * Raising the timeout is a treadmill that ends at that same wall. Making the
             * jobs small ends it. A box is one Overpass query and one upsert: minutes,
             * and what it fetched is on disk before the job returns.
             */
            if (in_array($source, self::BOXED_SOURCES, true)) {
                $this->dispatchBoxes($region, $registry, $source);

                return;
            }

            // Unboxed sources (Wikidata SPARQL, Mérimée, DATAtourisme) take the region
            // whole: they are paginated APIs where 45 small queries would be slower AND
            // ruder than one.
            try {
                $result = $ingest->ingest($region, $source);
                Log::info("world-model ingest {$this->regionKey}/{$source}", $result);
            } catch (Throwable $e) {
                Log::warning("world-model ingest {$this->regionKey}/{$source} degraded: {$e->getMessage()}");
            }

            $this->chainOnwardFromIngest($registry, $source);

            return;
        }

        if ($this->phase === 'resolve') {
            $tiles = $resolve->unresolvedTiles($region, self::RESOLVE_BATCH_TILES);

            if ($tiles === []) {
                self::dispatch($this->regionKey, 'evidence');

                return;
            }

            $totals = $resolve->resolveTiles($tiles);
            Log::info("world-model resolve {$this->regionKey} batch", [...$totals, 'tiles' => count($tiles)]);

            self::dispatch($this->regionKey, 'resolve');

            return;
        }

        /*
         * The NARRATIVE layer (DATA-SOURCES §2 — Wikipedia, P1, and it was never built).
         *
         * Without it the curation selector only accepted DATAtourisme and Mérimée,
         * which are both FRENCH — so Stockholm, the home region, could not produce a
         * single curation candidate however many times it was rebuilt. "OSM has no
         * stories", as DATA-SOURCES puts it, and Wikidata's p31 is a type code.
         *
         * The concordance was already there: places carry a `wikipedia` external id from
         * OSM's tags. We knew which article described each place; we had never read it.
         *
         * CC BY-SA → evidence store only, never places_core (conventions/09).
         */
        if ($this->phase === 'evidence') {
            $result = $wikipedia->fetchBatch();
            Log::info("world-model evidence {$this->regionKey} batch", $result);

            self::dispatch($this->regionKey, $result['candidates'] > 0 ? 'evidence' : 'photos');

            return;
        }

        if ($this->phase === 'photos') {
            $result = $photos->fetchBatch();
            Log::info("world-model photos {$this->regionKey} batch", $result);

            if ($result['candidates'] > 0) {
                self::dispatch($this->regionKey, 'photos');

                return;
            }

            self::dispatch($this->regionKey, 'warm');

            return;
        }

        // phase: warm — pre-fill the shared tile cache for the region.
        //
        // Without this the first session in a cold tile pays for every scout on
        // the read path, which is the latency the cache exists to remove
        // (PRD §9.3). WarmTileJob existed but nothing dispatched it; the runner
        // warmed inline instead, and its own docblock said the opposite.
        $this->warmRegionTiles($this->regionKey);

        // Release the claim: the button is live again, and the console stops saying
        // "building" for a region that finished an hour ago.
        $status->finish($this->regionKey);

        Log::info("world-model build complete for {$this->regionKey}");
    }

    /**
     * A dead job must not strand the region.
     *
     * `handle()` already treats a failed *source* as degraded-not-fatal, but that
     * catch cannot save us from the job itself being killed — a worker timeout is
     * delivered by killing the process, so no `catch` inside `handle()` ever runs
     * and nothing dispatches the next phase. That is how Nice stalled forever: the
     * OSM ingest was killed at 900 s, and `resolve`, `photos` and `warm` were never
     * queued. With `tries = 1` there was no second attempt to rescue it either, so
     * the region simply stopped, silently, with an empty `places_core`.
     *
     * So the chain is carried forward from the failure handler too. Each hop only
     * ever moves *onward*, so a repeatedly-failing phase drains the chain rather
     * than looping on it.
     */
    public function failed(Throwable $e): void
    {
        Log::error("world-model {$this->phase} {$this->regionKey} failed — carrying the chain onward", [
            'source' => $this->source,
            'error' => $e->getMessage(),
        ]);

        match ($this->phase) {
            'ingest' => $this->chainOnwardFromIngest(app(SourceRegistry::class), $this->source ?? app(SourceRegistry::class)->keys()[0]),
            'resolve' => self::dispatch($this->regionKey, 'evidence'),
            'evidence' => self::dispatch($this->regionKey, 'photos'),
            'photos' => self::dispatch($this->regionKey, 'warm'),

            // `warm` is the last phase: there is nowhere to chain onward TO, and until
            // now that meant nothing released the claim either. The console then said
            // "building" — with the button greyed out — for six hours, for a build that
            // had already died. A claim must not outlive the work.
            default => app(RegionBuildStatus::class)->finish($this->regionKey),
        };
    }

    /**
     * Queue every box of this source AT ONCE, and move on when they are all done.
     *
     * BATCHED, NOT CHAINED — and they are not the same thing. Chaining (box 12
     * dispatches box 13) only advances if box 12's failure handler actually runs, so
     * a SIGKILL, an OOM or a container restart strands the region in silence: the
     * same class of bug this whole exercise is about. A batch has no such thread to
     * cut. The other 44 boxes never depended on box 12.
     *
     * They still run STRICTLY IN SEQUENCE, because the ingest supervisor is
     * maxProcesses 1 (public Overpass allows ~2 slots per IP, and running the corridor
     * cities back to back is what cost Nantes its entire OSM layer). Sequence is the
     * worker's decision, which is exactly where it belongs — and the day we self-host
     * Overpass, parallelism is a config change rather than a rewrite.
     *
     * allowFailures(): a dead box is degraded, never fatal (conventions/09). 44 of 45
     * boxes is a usable city.
     */
    private function dispatchBoxes(IngestRegion $region, SourceRegistry $registry, string $source): void
    {
        $boxes = $region->boxes();
        $regionKey = $this->regionKey;

        Log::info("world-model ingest {$regionKey}/{$source}: queueing boxes", ['boxes' => count($boxes)]);

        Bus::batch(array_map(
            static fn (int $index): IngestRegionBoxJob => new IngestRegionBoxJob($regionKey, $source, $index),
            array_keys($boxes),
        ))
            ->name("ingest {$regionKey}/{$source}")
            ->allowFailures()
            ->onQueue(QueueLane::Ingest->value)
            ->onConnection(QueueLane::Ingest->connection())
            // Runs whether the batch succeeded, partly failed, or was cancelled: the
            // region must reach `resolve` regardless, or a single bad box strands it.
            ->finally(static function () use ($regionKey, $source): void {
                app(self::class, ['regionKey' => $regionKey, 'phase' => 'ingest', 'source' => $source])
                    ->chainOnwardFromIngest(app(SourceRegistry::class), $source);
            })
            ->dispatch();
    }

    /** Next source in the registry, or on to `resolve` when this was the last one. */
    public function chainOnwardFromIngest(SourceRegistry $registry, string $source): void
    {
        $sources = $registry->keys();
        $index = array_search($source, $sources, true);
        $next = $index === false ? null : ($sources[$index + 1] ?? null);

        $next === null
            ? self::dispatch($this->regionKey, 'resolve')
            : self::dispatch($this->regionKey, 'ingest', $next);
    }

    /** One WarmTileJob per (tile, scout); ShouldBeUnique collapses duplicates. */
    private function warmRegionTiles(string $regionKey): void
    {
        $tiles = app(ResolveRegion::class)->tilesFor(IngestRegion::named($regionKey));

        foreach ($tiles as $tile) {
            foreach (app()->tagged('tile-scouts') as $scout) {
                WarmTileJob::dispatch($scout::class, $tile);
            }
        }

        Log::info("world-model warm {$regionKey}", ['tiles' => count($tiles)]);
    }
}
