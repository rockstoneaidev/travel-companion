<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Curation\Actions\DraftCuratedItems;
use App\Domain\Curation\Actions\GroundCuratedItem;
use App\Domain\Curation\Enums\CurationStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Pipeline steps 2–3 (CURATION §3) from a harvest file: draft + ground.
 * Everything lands in the admin review queue — nothing this command creates
 * can reach a feed without a human approving it.
 */
final class CurationDraftCommand extends Command
{
    protected $signature = 'curation:draft {region : Region slug, e.g. stockholm} {file : Harvest JSON path}';

    protected $description = 'Draft curated items from a harvest file and ground them against the canonical places';

    public function handle(DraftCuratedItems $draft, GroundCuratedItem $ground): int
    {
        $harvested = json_decode(File::get($this->argument('file')), true, flags: JSON_THROW_ON_ERROR);

        $items = $draft((string) $this->argument('region'), $harvested);

        $grounded = 0;
        $needsGrounding = 0;
        foreach ($items as $item) {
            $ground($item);
            $item->status === CurationStatus::InReview ? $grounded++ : $needsGrounding++;
        }

        $this->components->twoColumnDetail('Drafted', (string) count($items));
        $this->components->twoColumnDetail('Grounded → in_review', (string) $grounded);
        $this->components->twoColumnDetail('needs_grounding', (string) $needsGrounding);
        $this->components->info('Review queue: /admin/curation — nothing is served until approved.');

        return self::SUCCESS;
    }
}
