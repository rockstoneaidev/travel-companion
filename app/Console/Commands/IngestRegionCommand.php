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
    protected $signature = 'ingest:region {region : Region key, e.g. stockholm-test} {--source=* : Limit to specific source keys}';

    protected $description = 'Ingest open-core sources for a bounded region into source_items';

    public function handle(RegionIngest $ingest, SourceRegistry $registry): int
    {
        $region = IngestRegion::named($this->argument('region'));
        $sources = $this->option('source') ?: $registry->keys();

        $failures = 0;

        foreach ($sources as $key) {
            $this->components->task("{$region->key} ← {$key}", function () use ($ingest, $region, $key, &$failures): bool {
                try {
                    $result = $ingest->ingest($region, $key);
                    $this->line(sprintf(
                        '    fetched %s raw → %s candidates across %s tiles',
                        number_format($result['fetched']),
                        number_format($result['candidates']),
                        number_format($result['tiles']),
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
}
