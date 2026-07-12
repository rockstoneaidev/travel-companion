<?php

declare(strict_types=1);

namespace App\Jobs\Ingest;

use App\Domain\Places\Services\FetchCommonsImages;
use App\Domain\Places\Services\ResolveRegion;
use App\Domain\Sources\Data\IngestRegion;
use App\Domain\Sources\Services\RegionIngest;
use App\Domain\Sources\Services\SourceRegistry;
use App\Enums\QueueLane;
use App\Jobs\Scouts\WarmTileJob;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
     * Must stay BELOW the `redis-long` connection's retry_after (1800s).
     *
     * The old value was 420s on a connection whose retry_after was 90s, which is
     * how Dijon died: after 90 seconds the queue decided the still-running job was
     * dead, handed it to a second worker, and it failed as MaxAttemptsExceeded
     * having done nothing wrong. Enforced now by tests/Arch/QueueConfigTest.php.
     */
    public int $timeout = 900;

    public int $tries = 1;

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

    public function handle(RegionIngest $ingest, SourceRegistry $registry, ResolveRegion $resolve, FetchCommonsImages $photos): void
    {
        $region = IngestRegion::named($this->regionKey);

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
            $sources = $registry->keys();
            $source = $this->source ?? $sources[0];
            $index = array_search($source, $sources, true);

            try {
                $result = $ingest->ingest($region, $source);
                Log::info("world-model ingest {$this->regionKey}/{$source}", $result);
            } catch (Throwable $e) {
                Log::warning("world-model ingest {$this->regionKey}/{$source} degraded: {$e->getMessage()}");
            }

            $next = $sources[$index + 1] ?? null;

            $next === null
                ? self::dispatch($this->regionKey, 'resolve')
                : self::dispatch($this->regionKey, 'ingest', $next);

            return;
        }

        if ($this->phase === 'resolve') {
            $tiles = $resolve->unresolvedTiles($region, self::RESOLVE_BATCH_TILES);

            if ($tiles === []) {
                self::dispatch($this->regionKey, 'photos');

                return;
            }

            $totals = $resolve->resolveTiles($tiles);
            Log::info("world-model resolve {$this->regionKey} batch", [...$totals, 'tiles' => count($tiles)]);

            self::dispatch($this->regionKey, 'resolve');

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

        Log::info("world-model build complete for {$this->regionKey}");
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
