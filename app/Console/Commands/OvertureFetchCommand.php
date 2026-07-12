<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sources\Data\IngestRegion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Fetch the Overture extract for a region.
 *
 * Overture ships as cloud-hosted GeoParquet and the supported client is the
 * `overturemaps` CLI, so this is a wrapper rather than an adapter: the bbox
 * comes from IngestRegion, which is the single source of truth for a region's
 * bounds. Without this, rebuilding the world model from a clean checkout meant
 * copying a bbox out of a docblock by hand.
 */
final class OvertureFetchCommand extends Command
{
    protected $signature = 'ingest:overture-fetch {region : Region key, e.g. stockholm-test} {--force : Re-download even if the extract exists}';

    protected $description = 'Download the Overture places extract for a region (requires the `overturemaps` CLI)';

    public function handle(): int
    {
        $region = IngestRegion::named($this->argument('region'));
        $relative = "ingest/overture/{$region->key}.geojson";

        if (Storage::disk('local')->exists($relative) && ! $this->option('force')) {
            $this->components->info("Extract already present: storage/app/{$relative} (--force to re-download)");

            return self::SUCCESS;
        }

        $absolute = Storage::disk('local')->path($relative);
        Storage::disk('local')->makeDirectory('ingest/overture');

        $command = [
            'overturemaps', 'download',
            sprintf('--bbox=%F,%F,%F,%F', $region->west, $region->south, $region->east, $region->north),
            '-f', 'geojson',
            '--type=place',
            '-o', $absolute,
        ];

        $this->components->info("Downloading Overture places for {$region->name}…");
        $this->line('  '.implode(' ', $command));

        $process = new Process($command, timeout: 1800);

        try {
            $process->mustRun(function (string $type, string $buffer): void {
                $this->output->write($buffer);
            });
        } catch (ProcessFailedException $e) {
            $this->components->error('overturemaps failed. Is the CLI installed? `pipx install overturemaps`');
            $this->line($e->getMessage());

            return self::FAILURE;
        }

        $features = count(json_decode(Storage::disk('local')->get($relative), true)['features'] ?? []);
        $this->components->info("Wrote {$features} features to storage/app/{$relative}");

        return self::SUCCESS;
    }
}
