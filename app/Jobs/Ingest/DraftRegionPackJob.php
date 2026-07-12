<?php

declare(strict_types=1);

namespace App\Jobs\Ingest;

use App\Domain\Curation\Actions\DraftPackFromWorldModel;
use App\Domain\Sources\Services\RegionBuildStatus;
use App\Enums\QueueLane;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Draft a region's curation pack (CURATION §4) — from the admin console rather than
 * over SSH.
 *
 * The console had a build button and no draft button, and "build world model" has no
 * drafting phase: ingest → resolve → photos → warm, and nothing else. So the review
 * queue stayed empty for days and looked broken, when in fact nothing had ever been
 * asked to fill it. Manual is fine; INVISIBLE is not.
 *
 * It stays a deliberate act, not a phase of the build, because it calls the LLM once
 * per candidate and costs real money. A "build" button that quietly spends tokens is
 * a bad button. A button that says what it will spend is a good one.
 *
 * Nothing it drafts is served: every item lands in review, and approval is what makes
 * a claim Tier-A (CURATION §5).
 */
final class DraftRegionPackJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Gemini, once per candidate. A big region is a long job. */
    public int $timeout = 1500;

    public int $tries = 1;

    public function __construct(
        public readonly string $regionKey,
        public readonly int $target,
    ) {
        $this->onQueue(QueueLane::Ingest->value);
        $this->onConnection(QueueLane::Ingest->connection());
    }

    public function uniqueId(): string
    {
        return "draft-pack:{$this->regionKey}";
    }

    public function handle(DraftPackFromWorldModel $draft, RegionBuildStatus $status): void
    {
        try {
            $result = $draft($this->regionKey, $this->target);

            Log::info("curation draft-pack {$this->regionKey}", [
                ...$result,
                'target' => $this->target,
            ]);
        } finally {
            // Release the claim whatever happened. A draft that died must not leave the
            // button disabled forever — the cache TTL would eventually free it, but
            // "eventually" is not a user experience.
            $status->finishDraft($this->regionKey);
        }
    }

    /** Same reason as above: a dead job must not wedge the button. */
    public function failed(\Throwable $e): void
    {
        app(RegionBuildStatus::class)->finishDraft($this->regionKey);

        Log::warning("curation draft-pack {$this->regionKey} failed", ['error' => $e->getMessage()]);
    }
}
