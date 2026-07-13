<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sources\Data\IngestRegion;
use App\Domain\Sources\Services\RegionIngest;
use App\Domain\Sources\Services\SourceRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * Thin console wrapper over the RegionIngest domain service (conventions/01 —
 * no business logic here). A failed source is a degraded result, not a failed
 * run (conventions/09).
 */
final class IngestRegionCommand extends Command
{
    protected $signature = 'ingest:region {region : Region key, e.g. stockholm} {--source=* : Limit to specific source keys}';

    protected $description = 'Ingest open-core sources for a bounded region into source_items';

    /**
     * Boxed sources are ingested BOX BY BOX here too, exactly as the queue does it
     * (BuildRegionWorldModelJob::BOXED_SOURCES).
     *
     * This command used to hand the whole region to one `ingest()` call, which is the
     * memory-unbounded path, and running `ingest:region paris` on the staging box is what
     * killed the app container — taking the site with it, because Horizon lives in that
     * container. The queue had already learned this lesson and boxed the work; the CLI
     * had not, so the same bug was still one command away from anyone who reached for it.
     *
     * Keeping the two paths honest about each other matters more than the duplication:
     * a "safe" pipeline with an unsafe door in the side is not safe, it is just harder to
     * blame.
     */
    private const BOXED_SOURCES = ['osm', 'wikidata'];

    public function handle(RegionIngest $ingest, SourceRegistry $registry): int
    {
        $region = IngestRegion::named($this->argument('region'));
        $sources = $this->option('source') ?: $registry->keys();

        $failures = 0;

        foreach ($sources as $key) {
            $this->components->task("{$region->key} ← {$key}", function () use ($ingest, $region, $key, &$failures): bool {
                try {
                    $result = in_array($key, self::BOXED_SOURCES, true)
                        ? $this->ingestBoxed($ingest, $region, $key)
                        : $ingest->ingest($region, $key);

                    $this->line(sprintf(
                        '    fetched %s raw → %s candidates across %s tiles · peak %s MB',
                        number_format($result['fetched']),
                        number_format($result['candidates']),
                        number_format($result['tiles']),
                        $result['peak_mb'],
                    ));

                    return true;
                } catch (Throwable $e) {
                    $failures++;
                    $this->warn('    degraded: '.$e->getMessage());

                    return false;
                }
            });
        }

        return $failures === count($sources) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * One box at a time, sequentially — the same shape IngestRegionBoxJob runs, for the
     * same two reasons: a box bounds memory, and public Overpass allows ~2 slots per IP,
     * so parallel boxes are how you lose a city's OSM layer to a rate limit.
     *
     * A dead box is degraded, never fatal (conventions/09): 44 of 45 boxes is a usable
     * city, and abandoning the region to protect a few square kilometres of it is the
     * wrong trade.
     *
     * @return array{fetched: int, candidates: int, tiles: int, peak_mb: float}
     */
    private function ingestBoxed(RegionIngest $ingest, IngestRegion $region, string $key): array
    {
        $boxes = $region->boxes();
        $totals = ['fetched' => 0, 'candidates' => 0, 'tiles' => 0, 'peak_mb' => 0.0];
        $cells = [];

        foreach ($boxes as $i => $box) {
            try {
                $result = $ingest->ingest($region, $key, $box);
            } catch (Throwable $e) {
                $this->warn(sprintf('    box %d/%d degraded: %s', $i + 1, count($boxes), $e->getMessage()));

                continue;
            }

            $totals['fetched'] += $result['fetched'];
            $totals['candidates'] += $result['candidates'];
            $totals['peak_mb'] = max($totals['peak_mb'], $result['peak_mb']);
            $cells[] = $result['tiles'];
        }

        // Tiles are counted per box, so this over-counts a tile straddling a boundary.
        // Close enough for a console line, and not worth a second pass over the region.
        $totals['tiles'] = array_sum($cells);

        return $totals;
    }
}
