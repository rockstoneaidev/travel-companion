<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Curation\Actions\PublishPack;
use App\Domain\Curation\Models\Pack;
use Illuminate\Console\Command;

/**
 * Publish a Regional Knowledge Pack (CURATION §3 step 5).
 *
 * PublishPack existed but nothing called it, so a pack could be drafted,
 * grounded and reviewed and still never ship. This is the missing step.
 *
 * Publishing a pack with almost nothing approved in it is the failure mode
 * worth guarding: the pack would be "published" and the feed would still have
 * no curated voice in it. So it refuses below target unless forced.
 */
final class CurationPublishCommand extends Command
{
    protected $signature = 'curation:publish {region=stockholm} {--effort=0 : Review minutes spent on this version} {--force : Publish even if under the target item count}';

    protected $description = 'Publish a regional knowledge pack — approved items become Tier-A evidence in the feed';

    private const TARGET_APPROVED = 25;

    public function handle(PublishPack $publish): int
    {
        $pack = Pack::query()->where('region_slug', $this->argument('region'))->first();

        if ($pack === null) {
            $this->components->error("No pack for region \"{$this->argument('region')}\".");

            return self::FAILURE;
        }

        $approved = $publish->approvedCount($pack);

        if ($approved < self::TARGET_APPROVED && ! $this->option('force')) {
            $this->components->error(sprintf(
                '%s has %d approved item%s — the target is %d.',
                $pack->region_slug, $approved, $approved === 1 ? '' : 's', self::TARGET_APPROVED,
            ));
            $this->line('  Publishing now would ship a pack that puts almost no curated voice in the feed.');
            $this->line('  Review the queue at /admin/curation, or pass --force if this is deliberate.');

            return self::FAILURE;
        }

        $pack = $publish($pack, (int) $this->option('effort'));

        $this->components->info(sprintf(
            'Published %s v%d — %d approved items across %d tiles · %d review minutes logged.',
            $pack->region_slug, $pack->pack_version, $approved, count($pack->h3_set), $pack->effort_minutes,
        ));

        return self::SUCCESS;
    }
}
