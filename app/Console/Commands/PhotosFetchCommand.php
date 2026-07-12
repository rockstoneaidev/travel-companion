<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Places\Services\FetchCommonsImages;
use Illuminate\Console\Command;

/** Thin wrapper (conventions/01): fetch Commons images until no candidates remain. */
final class PhotosFetchCommand extends Command
{
    protected $signature = 'photos:fetch';

    protected $description = 'Fetch Wikimedia Commons images (via Wikidata P18) for places that lack one';

    public function handle(FetchCommonsImages $fetch): int
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
