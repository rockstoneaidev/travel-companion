<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Places\Services\GazetteerLoader;
use Illuminate\Console\Command;

/**
 * Load settlement names into the gazetteer, one or more countries at a time (PLAN-DRIVEN-
 * INGESTION §7 phase 1). Thin wrapper (conventions/04): the policy lives in GazetteerLoader.
 *
 *   php artisan gazetteer:load SE FR
 *
 * Country-scoped and run rarely — this is reference data, loaded for the countries we operate
 * in and expanded when a new one earns it.
 */
final class GazetteerLoadCommand extends Command
{
    protected $signature = 'gazetteer:load {country* : ISO 3166-1 alpha-2 codes, e.g. SE FR}';

    protected $description = 'Load settlement names into the gazetteer from OSM, per country';

    public function handle(GazetteerLoader $loader): int
    {
        /** @var list<string> $countries */
        $countries = $this->argument('country');

        foreach ($countries as $code) {
            $this->components->info("Loading {$code}…");
            $count = $loader->load($code);
            $this->components->twoColumnDetail(strtoupper($code), number_format($count).' settlements');
        }

        return self::SUCCESS;
    }
}
