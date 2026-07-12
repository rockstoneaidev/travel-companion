<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Curation\Actions\DraftPackFromWorldModel;
use Illuminate\Console\Command;

/**
 * Fill a pack's review queue from the world model (E14, CURATION §3–4).
 *
 * The targets are CURATION §4's, and they are not arbitrary: they are how many
 * items a city needs before the curated layer is actually felt in a feed.
 */
final class CurationDraftPackCommand extends Command
{
    protected $signature = 'curation:draft-pack {region : Region key, e.g. paris} {--target= : Approved-item target (defaults to the CURATION §4 plan)}';

    protected $description = 'Draft curated items for a region from stored evidence, into the admin review queue';

    /** CURATION §4's pack plan. */
    private const TARGETS = [
        'paris' => 40,
        'nice' => 30,
        'nantes' => 30,
        'dijon' => 25,
        'lyon' => 20,
        'bordeaux' => 20,
        'toulouse' => 20,
        'stockholm-test' => 30,
    ];

    public function handle(DraftPackFromWorldModel $draft): int
    {
        $region = (string) $this->argument('region');
        $target = (int) ($this->option('target') ?: self::TARGETS[$region] ?? 20);

        $this->components->info("Drafting {$region} — target {$target} items. Every draft lands in review; nothing is served until approved.");

        $result = $draft($region, $target);

        $this->components->twoColumnDetail('Considered', (string) $result['considered']);
        $this->components->twoColumnDetail('Drafted → in_review', (string) $result['drafted']);
        $this->components->twoColumnDetail('Skipped (evidence too thin)', (string) $result['skipped']);

        if ($result['drafted'] < $target) {
            $this->newLine();
            $this->components->warn(sprintf(
                'Only %d of %d drafted — the region has run out of places with usable evidence.',
                $result['drafted'], $target,
            ));
            $this->line('  Ingest more sources for this region, or lower the target. Do NOT lower the evidence bar.');
        }

        $this->newLine();
        $this->components->info('Review queue: /admin/curation');

        return self::SUCCESS;
    }
}
