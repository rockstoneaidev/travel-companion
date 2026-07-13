<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Curation\Models\CuratedItem;
use App\Domain\Curation\Services\ClaimGuard;
use Illuminate\Console\Command;

/**
 * Cuts perishable sentences out of curated items that are already in the database.
 *
 * The drafter now trims before a draft exists and the review gate trims before an
 * approval lands, so nothing NEW carries a price. This is the one-off for the rows
 * written before that: a review queue full of good claims about real places, each
 * ending in a coffee price or an opening time, with Reject as the only button.
 *
 * Idempotent — running it again on a clean database costs nothing.
 */
final class CurationTrimPerishableCommand extends Command
{
    protected $signature = 'curation:trim-perishable {--dry-run : Show what would change without writing}';

    protected $description = 'Remove perishable facts (prices, opening hours) from existing curated claims';

    public function handle(ClaimGuard $guard): int
    {
        $dirty = CuratedItem::query()
            ->get(['id', 'region_slug', 'title', 'claim', 'status', 'authored_by'])
            ->filter(fn (CuratedItem $item): bool => $guard->isPerishable((string) $item->claim));

        if ($dirty->isEmpty()) {
            $this->components->info('No curated claim names a price or an opening time.');

            return self::SUCCESS;
        }

        $this->components->warn(sprintf('%d claim(s) carry a fact that will go stale:', $dirty->count()));
        $this->newLine();

        $trimmed = 0;
        $unsalvageable = 0;

        foreach ($dirty as $item) {
            $clean = $guard->trimPerishable((string) $item->claim);

            $this->line("  <fg=yellow>[{$item->region_slug}] {$item->title}</> ({$item->status->value})");
            $this->line('    was: '.$item->claim);

            if ($clean === '') {
                // Nothing but the price. There is no claim underneath it to keep.
                $this->line('    <fg=red>now: (nothing survives — the claim was only ever a price)</>');
                $unsalvageable++;

                continue;
            }

            $this->line('    <fg=green>now: '.$clean.'</>');
            $trimmed++;

            if ($this->option('dry-run')) {
                continue;
            }

            // A trim is an edit. The last hand on the text owns it — and it is no longer
            // purely what the model wrote.
            $item->forceFill(['claim' => $clean, 'authored_by' => 'human'])->save();
        }

        $this->newLine();

        $this->components->info(sprintf(
            '%s%d trimmed · %d unsalvageable (reject those by hand — the place may still be worth a fresh draft)',
            $this->option('dry-run') ? 'DRY RUN — nothing written. ' : '',
            $trimmed,
            $unsalvageable,
        ));

        return self::SUCCESS;
    }
}
