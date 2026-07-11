<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Places\Services\CoverageGeometry;
use App\Domain\Places\Services\ScoutRunner;
use App\Domain\Trips\Enums\TravelMode;
use Illuminate\Console\Command;

/**
 * Warm the shared tile cache for a hypothetical session — the E5
 * done-condition made observable: run it twice and watch the hit rate.
 */
final class ScoutWarmCommand extends Command
{
    protected $signature = 'scout:warm {lat} {lng} {--mode=walk} {--budget=120 : Time budget in minutes} {--heading= : Optional heading in degrees}';

    protected $description = 'Warm scout tile caches for a session-shaped coverage area';

    public function handle(CoverageGeometry $geometry, ScoutRunner $runner): int
    {
        $coverage = $geometry->forSession(
            lat: (float) $this->argument('lat'),
            lng: (float) $this->argument('lng'),
            mode: TravelMode::from($this->option('mode')),
            timeBudgetMinutes: (int) $this->option('budget'),
            headingDeg: $this->option('heading') !== null ? (int) $this->option('heading') : null,
        );

        $this->components->twoColumnDetail('Coverage', sprintf(
            '%d tiles (%d near / %d far), origin %s',
            count($coverage->allTiles()), count($coverage->nearTiles), count($coverage->farTiles), $coverage->originCell,
        ));

        $summary = $runner->warm($coverage, trigger: 'command');

        $this->table(
            ['Scout', 'Tiles', 'Hits', 'Filled', 'Candidates', 'Hit rate'],
            array_map(static fn (array $row): array => [
                $row['scout'], $row['tiles'], $row['hits'], $row['filled'], $row['candidates'],
                number_format($row['hit_rate'] * 100, 1).'%',
            ], $summary),
        );

        return self::SUCCESS;
    }
}
