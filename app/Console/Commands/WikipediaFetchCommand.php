<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Places\Services\FetchWikipediaExtracts;
use Illuminate\Console\Command;

/**
 * Drain the Wikipedia evidence backlog (DATA-SOURCES §2, P1 — the narrative layer).
 *
 * Thin wrapper (conventions/01), and the twin of `photos:fetch`. It exists because
 * the only other way to fetch extracts was the `evidence` phase of
 * BuildRegionWorldModelJob, which self-dispatches through the queue — so if the chain
 * dies (no worker, a failed job), the backlog is stranded and there is no way to pick
 * it back up without rebuilding the whole world model.
 *
 * That is not hypothetical. It is exactly how Stockholm ended up with 4,326 places
 * carrying a `wikipedia` external id and 20 stored extracts: the concordance was
 * there, the articles were there, and nothing had gone and read them. Stockholm's ONLY
 * evidence source is Wikipedia — DATAtourisme and Mérimée are French — so the curation
 * selector, which mandates evidence, had almost nothing it was allowed to draft.
 *
 * THE LOOP STOPS ON PROGRESS, NOT ON CANDIDATES, and that distinction is the whole
 * reason this is not a copy of PhotosFetchCommand.
 *
 * A place whose article does not exist stores no extract — "a missing article is a
 * fact about the world, not an error" — but it also stays a candidate, forever. The
 * batch query is `ORDER BY place_id LIMIT n`, so a run of permanently-missing titles
 * sits at the front of the queue and is re-selected on every pass. `while (candidates
 * > 0)` therefore never terminates: it re-reads the same dead titles until someone
 * kills it. (BuildRegionWorldModelJob's `evidence` phase has the same shape and can
 * livelock the same way — worth fixing there too.)
 *
 * Stopping when a batch stores nothing is the honest condition: it means this pass
 * learned nothing, so another identical pass will learn nothing either.
 */
final class WikipediaFetchCommand extends Command
{
    protected $signature = 'wikipedia:fetch {--batch=60 : Places per API pass}';

    protected $description = 'Fetch Wikipedia intro extracts (CC BY-SA, evidence-only) for places that have a wikipedia id but no stored article';

    public function handle(FetchWikipediaExtracts $fetch): int
    {
        $batch = max(1, (int) $this->option('batch'));

        $this->components->info('Reading the articles we already knew the names of. Evidence store only — never places_core (CC BY-SA).');

        $totalCandidates = 0;
        $totalExtracts = 0;
        $throttles = 0;

        do {
            $result = $fetch->fetchBatch($batch);

            $totalCandidates += $result['candidates'];
            $totalExtracts += $result['extracts'];

            if ($result['throttled']) {
                $throttles++;
                $this->output->write('~');   // told to slow down; those rows stay candidates

                continue;
            }

            $this->output->write($result['extracts'] > 0 ? '.' : 'x');

            // Stop on NO PROGRESS — not on "no candidates". A place whose article does
            // not exist stores nothing and stays a candidate forever, so `candidates`
            // never reaches zero and is not a terminator. A throttled pass is not a
            // lack of progress either: it is a pass that never happened, so it does
            // not count against us here.
        } while ($result['extracts'] > 0 || $result['throttled']);

        $this->newLine(2);
        $this->components->twoColumnDetail('Places checked', number_format($totalCandidates));
        $this->components->twoColumnDetail('Extracts stored', number_format($totalExtracts));

        if ($throttles > 0) {
            $this->components->twoColumnDetail('Rate-limited passes', number_format($throttles));
        }

        if ($totalExtracts === 0) {
            $this->components->warn('Nothing stored. Either the backlog is already drained, or every remaining title is a dead link.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->info('Stockholm can now be drafted: php artisan curation:draft-pack stockholm');

        return self::SUCCESS;
    }
}
