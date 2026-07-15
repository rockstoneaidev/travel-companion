<?php

declare(strict_types=1);

namespace App\Jobs\Ingest;

use App\Domain\Places\Services\FetchPlaceImages;
use App\Domain\Places\Services\FetchWikipediaExtracts;
use App\Domain\Places\Services\ResolveRegion;
use App\Domain\Places\Services\ScoutRunner;
use App\Domain\Sources\Data\IngestRegion;
use App\Domain\Sources\Services\RegionBuildStatus;
use App\Domain\Sources\Services\RegionCatalog;
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
        /*
         * Where the person who asked for this region is actually standing (E48).
         *
         * Boxes are ingested NEAREST-FIRST when we know. Stockholm is ~45 boxes at
         * roughly a minute each, so a region learned on demand takes the better part of
         * an hour — and ingested in grid order, the user in the middle of it sees nothing
         * until it is nearly done. Nearest-first, the tiles they are standing in land in
         * the first minute or two and the feed comes alive while the rest fills in behind
         * them. Same work, a completely different experience of waiting for it.
         */
        public readonly ?float $nearLat = null,
        public readonly ?float $nearLng = null,
    ) {
        /*
         * The progressive resolve does NOT go on the ingest lane, and that is the whole
         * point of it.
         *
         * The ingest lane is maxProcesses 1 — deliberately, because public Overpass gives
         * us about two slots and being rude to it costs an entire city (the comment on
         * dispatchBoxes() is the scar). So a resolve queued there lands BEHIND all
         * fifty-five boxes and cannot possibly run until the ingest it was meant to
         * pre-empt has already finished. I queued it there and watched it never run.
         *
         * It is pure database work — source items into places — so it belongs on a lane
         * that is free to move while Overpass makes us wait.
         */
        $lane = $phase === 'resolve-progressive' ? QueueLane::Default : QueueLane::Ingest;

        $this->onQueue($lane->value);
        $this->onConnection($lane->connection());
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

    private function catalog(): RegionCatalog
    {
        return app(RegionCatalog::class);
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
        FetchPlaceImages $photos,   // E50: all four free sources, not just Wikidata P18
        RegionBuildStatus $status,
        FetchWikipediaExtracts $wikipedia,
    ): void {
        $region = $this->catalog()->named($this->regionKey);

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

        /*
         * RESOLVE WHAT WE HAVE SO FAR, while the rest is still being fetched (E48).
         *
         * Without this, ingesting the boxes nearest the user buys exactly nothing. A box
         * writes SOURCE ITEMS; only the `resolve` phase turns those into `places`, and
         * `resolve` runs when the batch FINISHES — all 55 boxes of it, which on a public
         * Overpass being polite to us is a couple of hours. So the person who triggered
         * the region would stare at an empty feed for the entire ingest and then have a
         * city appear at once, long after they had gone home.
         *
         * A completed box dispatches this. It resolves ONE batch of tiles and stops —
         * no chaining, no evidence phase, no opinions about what happens next; that is
         * the full `resolve` phase's job when the ingest is actually done. Unique per
         * region, so fifty-five boxes finishing do not queue fifty-five resolves.
         */
        if ($this->phase === 'resolve-progressive') {
            $tiles = $resolve->unresolvedTiles($region, self::RESOLVE_BATCH_TILES);

            if ($tiles !== []) {
                $totals = $resolve->resolveTiles($tiles);

                /*
                 * AND TELL THE SCOUTS THE GROUND HAS CHANGED.
                 *
                 * This is the line that makes the whole progressive ingest visible, and
                 * without it everything above it is theatre. `DbScout`'s cache TTL is a
                 * DAY, and "there is nothing in this hexagon" caches exactly like any other
                 * answer — so the scouts that swept this region while it was still virgin
                 * ground go on serving that emptiness for twenty-four hours, while the
                 * places land in the table right underneath them.
                 *
                 * The founder watched it: 27 canonical places in Skellefteå, and a pipeline
                 * log reading "49 tiles (49 hit, 0 filled), 0 candidates". Every tile a hit.
                 * Every hit empty. The feed said "nothing worth interrupting you for" about
                 * a town it had just finished mapping.
                 *
                 * So each batch of resolved tiles drops its own cached answers, and the very
                 * next pull re-reads them from the database. The region becomes visible in
                 * rings, from the user outward — which is exactly what nearest-first ingest
                 * was for.
                 */
                $forgotten = app(ScoutRunner::class)->forgetTiles($tiles);

                Log::info("world-model resolve {$this->regionKey} (progressive)", [
                    ...$totals,
                    'tiles' => count($tiles),
                    'scout_cache_dropped' => $forgotten,
                ]);
            }

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
        /*
         * ===================================================================
         *  A PHASE ADVANCES ON PROGRESS, NOT ON REMAINING CANDIDATES.
         * ===================================================================
         *
         * Both of these phases used to re-dispatch themselves while `candidates > 0`,
         * and that is an infinite loop wearing a plausible condition. `candidates` is
         * "rows that still have no extract/image" — and a place whose Wikipedia article
         * has no intro, or which simply has no Commons photo, can NEVER acquire one. It
         * stays a candidate for ever, so the phase re-queues itself for ever.
         *
         * Lyon did exactly that: `world-model evidence lyon`, candidates 26, 126 times in
         * ninety seconds, indefinitely — a hot loop that filled the queue with a job that
         * could not finish and hammered Wikipedia while doing it.
         *
         * The honest question is not "is there anything left?" — there always is — but
         * "did that batch actually achieve anything?". If a pass stores nothing, the
         * remaining rows are the ones it cannot help, and the phase is done.
         *
         * THROTTLED IS DIFFERENT, and it is why this is not just `extracts > 0`. "We were
         * told to slow down" is not "there is nothing here" (the fetcher is careful to
         * distinguish them, and its docblock explains what conflating them cost). A
         * throttled pass made no progress but WILL, so it comes back — after a delay,
         * rather than immediately, because re-asking a rate limiter at once is not
         * slowing down.
         */
        if ($this->phase === 'evidence') {
            $result = $wikipedia->fetchBatch();
            Log::info("world-model evidence {$this->regionKey} batch", $result);

            [$next, $delayMinutes] = self::afterEvidence($result);

            $job = self::dispatch($this->regionKey, $next);

            if ($delayMinutes > 0) {
                $job->delay(now()->addMinutes($delayMinutes));
            }

            return;
        }

        if ($this->phase === 'photos') {
            $result = $photos->fetchBatch();
            Log::info("world-model photos {$this->regionKey} batch", $result);

            self::dispatch($this->regionKey, self::afterPhotos($result));

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
        $pin = $this->pin();
        $boxes = $pin !== null ? $region->boxesNearest($pin[0], $pin[1]) : $region->boxes();
        $regionKey = $this->regionKey;

        Log::info("world-model ingest {$regionKey}/{$source}: queueing boxes", ['boxes' => count($boxes)]);

        $nearLat = $pin[0] ?? null;
        $nearLng = $pin[1] ?? null;

        Bus::batch(array_map(
            static fn (int $index): IngestRegionBoxJob => new IngestRegionBoxJob($regionKey, $source, $index, $nearLat, $nearLng),
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

    /**
     * What comes after an `evidence` batch — the rule that stops the loop.
     *
     * Pure and public so it can be asserted directly. The old rule lived inline as
     * `candidates > 0 ? 'evidence' : 'photos'`, which reads like a work queue and is in
     * fact non-terminating, and no test could see it because the services it depends on
     * are final and unmockable. A rule this consequential should be a thing you can
     * point at.
     *
     * @param  array{candidates: int, extracts: int, throttled: bool}  $result
     * @return array{0: string, 1: int} next phase, delay in minutes
     */
    public static function afterEvidence(array $result): array
    {
        // Told to slow down. That is not "nothing here" — it will succeed later, so come
        // back later. Immediately re-asking a rate limiter is not slowing down.
        if (($result['throttled'] ?? false) === true) {
            return ['evidence', 2];
        }

        // Progress, not remaining work. There are ALWAYS candidates left — a place whose
        // article has no intro can never acquire one — so "is there anything left?" never
        // becomes false and the phase never ends. "Did that batch achieve anything?" does.
        return [($result['extracts'] ?? 0) > 0 ? 'evidence' : 'photos', 0];
    }

    /**
     * @param  array{candidates: int, images: int}  $result
     */
    public static function afterPhotos(array $result): string
    {
        // Same rule, same reason: 40 places with no Commons photo will still have no
        // Commons photo next time round.
        return ($result['images'] ?? 0) > 0 ? 'photos' : 'warm';
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
        $tiles = app(ResolveRegion::class)->tilesFor(app(RegionCatalog::class)->named($regionKey));

        foreach ($tiles as $tile) {
            foreach (app()->tagged('tile-scouts') as $scout) {
                WarmTileJob::dispatch($scout::class, $tile);
            }
        }

        Log::info("world-model warm {$regionKey}", ['tiles' => count($tiles)]);
    }
}
