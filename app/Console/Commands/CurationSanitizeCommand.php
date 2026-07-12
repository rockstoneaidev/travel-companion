<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Curation\Models\CuratedItem;
use App\Support\PlainText;
use Illuminate\Console\Command;

/**
 * Strips source markup from curated items already in the database.
 *
 * The boundary is fixed (PlainText runs where evidence enters and where a claim
 * is written), but rows written before that fix are still live — and three of
 * them were APPROVED, meaning they would be read to a traveller. One said:
 *
 *     "...this lake, which allows [[water sports."
 *
 * This is the one-off that cleans them. It is idempotent, so running it again
 * costs nothing.
 */
final class CurationSanitizeCommand extends Command
{
    protected $signature = 'curation:sanitize {--dry-run : Show what would change without writing}';

    protected $description = 'Strip leaked wiki/HTML markup from curated titles and claims';

    public function handle(): int
    {
        $dirty = CuratedItem::query()
            ->get(['id', 'title', 'claim', 'status'])
            ->filter(fn (CuratedItem $item): bool => PlainText::hasMarkup($item->claim) || PlainText::hasMarkup($item->title));

        if ($dirty->isEmpty()) {
            $this->components->info('No curated item carries source markup.');

            return self::SUCCESS;
        }

        $this->components->warn(sprintf('%d item(s) carry markup a traveller should never see:', $dirty->count()));
        $this->newLine();

        foreach ($dirty as $item) {
            $cleanClaim = PlainText::clean($item->claim);

            $this->line("  <fg=gray>[{$item->status->value}]</> <options=bold>{$item->title}</>");
            $this->line("    <fg=red>- {$item->claim}</>");
            $this->line("    <fg=green>+ {$cleanClaim}</>");
            $this->newLine();

            if (! $this->option('dry-run')) {
                $item->forceFill([
                    'title' => PlainText::clean($item->title),
                    'claim' => $cleanClaim,
                ])->save();
            }
        }

        $this->option('dry-run')
            ? $this->components->info('Dry run — nothing written.')
            : $this->components->info("Cleaned {$dirty->count()} item(s).");

        return self::SUCCESS;
    }
}
