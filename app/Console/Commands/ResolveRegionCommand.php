<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Places\Services\ResolveRegion;
use App\Domain\Sources\Data\IngestRegion;
use Illuminate\Console\Command;

/**
 * Thin wrapper over ResolveRegion (conventions/01) — the same service the
 * admin console's build job uses. Idempotent (ENTITY-RESOLUTION §5).
 */
final class ResolveRegionCommand extends Command
{
    protected $signature = 'resolve:region {region : Region key, e.g. stockholm}';

    protected $description = 'Run entity resolution over all tiles of a region';

    public function handle(ResolveRegion $resolve): int
    {
        $region = IngestRegion::named($this->argument('region'));
        $totals = ['items' => 0, 'created' => 0, 'merged' => 0, 'review' => 0, 'explicit' => 0];
        $tileCount = 0;

        while (($tiles = $resolve->unresolvedTiles($region, 25)) !== []) {
            foreach ($resolve->resolveTiles($tiles) as $key => $value) {
                $totals[$key] += $value;
            }
            $tileCount += count($tiles);
            $this->output->write('.');
        }

        $this->newLine();
        $this->components->twoColumnDetail('Tiles', number_format($tileCount));
        foreach ($totals as $key => $value) {
            $this->components->twoColumnDetail(ucfirst($key), number_format($value));
        }

        return self::SUCCESS;
    }
}
