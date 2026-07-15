<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Places\Services\FetchPlaceImages;
use Illuminate\Console\Command;

/**
 * Backfill photos for places that lack one, across ALL free sources (E50).
 *
 * Thin wrapper (conventions/01). Points at `FetchPlaceImages` — the orchestrator that runs
 * Wikidata P18, the OSM image tags, Wikipedia lead images, Commons GeoSearch, Mapillary and
 * Openverse in confidence order — NOT the single Wikidata path it used to call. So this is
 * how you pick up the new sources for places ingested before them: no re-ingest, the places
 * already exist, this just fills the pictures they were missing.
 *
 * Loops until a whole pass finds no candidates: every source sentinels the places it looked
 * at but could not fill, so the candidate count drains to zero and the command terminates
 * rather than re-searching the same imageless places forever.
 */
final class PhotosFetchCommand extends Command
{
    protected $signature = 'photos:fetch';

    protected $description = 'Backfill place photos across all free sources (Commons, OSM tags, Wikipedia, GeoSearch, Mapillary, Openverse)';

    public function handle(FetchPlaceImages $fetch): int
    {
        $totalCandidates = 0;
        $totalImages = 0;

        do {
            $result = $fetch->fetchBatch();
            $totalCandidates += $result['candidates'];
            $totalImages += $result['images'];
            $this->output->write('.');
        } while ($result['candidates'] > 0);

        $this->newLine();
        $this->components->twoColumnDetail('Places checked', number_format($totalCandidates));
        $this->components->twoColumnDetail('Images stored', number_format($totalImages));

        return self::SUCCESS;
    }
}
