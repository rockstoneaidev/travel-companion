<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Places\Services\EntityResolver;
use App\Domain\Sources\Data\IngestRegion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Thin wrapper over the EntityResolver (conventions/01): resolves every tile
 * in a region that still has unresolved source items. Idempotent —
 * re-running only touches items without a decision at the current
 * resolver_version (ENTITY-RESOLUTION §5).
 */
final class ResolveRegionCommand extends Command
{
    protected $signature = 'resolve:region {region : Region key, e.g. stockholm-test}';

    protected $description = 'Run entity resolution over all tiles of a region';

    public function handle(EntityResolver $resolver): int
    {
        $region = IngestRegion::named($this->argument('region'));

        $tiles = DB::table('source_items')
            ->selectRaw('DISTINCT h3_index')
            ->whereRaw(
                'ST_Intersects(location::geometry, ST_MakeEnvelope(?, ?, ?, ?, 4326))',
                [$region->west, $region->south, $region->east, $region->north],
            )
            ->orderBy('h3_index')
            ->pluck('h3_index');

        $totals = ['items' => 0, 'created' => 0, 'merged' => 0, 'review' => 0, 'explicit' => 0];
        $bar = $this->output->createProgressBar(count($tiles));

        foreach ($tiles as $tile) {
            $stats = $resolver->resolveTile($tile);
            foreach ($stats as $key => $value) {
                $totals[$key] += $value;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->components->twoColumnDetail('Tiles', number_format(count($tiles)));
        foreach ($totals as $key => $value) {
            $this->components->twoColumnDetail(ucfirst($key), number_format($value));
        }

        return self::SUCCESS;
    }
}
