<?php

declare(strict_types=1);

namespace App\Jobs\Ingest;

use App\Domain\Places\Services\FetchCommonsImages;
use App\Domain\Places\Services\ResolveRegion;
use App\Domain\Sources\Data\IngestRegion;
use App\Domain\Sources\Services\RegionIngest;
use App\Domain\Sources\Services\SourceRegistry;
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
 * idempotency makes every re-entry safe.
 */
final class BuildRegionWorldModelJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    private const RESOLVE_BATCH_TILES = 12;

    public int $timeout = 420;

    public int $tries = 1;

    public function __construct(
        public readonly string $regionKey,
        public readonly string $phase = 'ingest',
    ) {}

    public function uniqueId(): string
    {
        return "{$this->regionKey}:{$this->phase}";
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(RegionIngest $ingest, SourceRegistry $registry, ResolveRegion $resolve, FetchCommonsImages $photos): void
    {
        $region = IngestRegion::named($this->regionKey);

        if ($this->phase === 'ingest') {
            foreach ($registry->keys() as $source) {
                try {
                    $result = $ingest->ingest($region, $source);
                    Log::info("world-model ingest {$this->regionKey}/{$source}", $result);
                } catch (Throwable $e) {
                    Log::warning("world-model ingest {$this->regionKey}/{$source} degraded: {$e->getMessage()}");
                }
            }

            self::dispatch($this->regionKey, 'resolve');

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

        // phase: photos — Commons imagery in the same self-chaining shape.
        $result = $photos->fetchBatch();
        Log::info("world-model photos {$this->regionKey} batch", $result);

        if ($result['candidates'] > 0) {
            self::dispatch($this->regionKey, 'photos');

            return;
        }

        Log::info("world-model build complete for {$this->regionKey}");
    }
}
