<?php

declare(strict_types=1);

namespace App\Jobs\Ranking;

use App\Domain\Privacy\Actions\InferHomeZone;
use App\Enums\QueueLane;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-consider where somebody lives (E40). Thin wrapper (conventions/08).
 *
 * Unique per user for a WHOLE DAY, unlike its siblings (segments, visits) which are unique
 * for five minutes. Home does not change between pings, or between hours — it changes over
 * weeks. Running this on every background event would scan a user's entire history dozens of
 * times a day to re-derive a number that moves a night at a time.
 */
final class InferHomeZoneJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId)
    {
        $this->onQueue(QueueLane::Default->value);
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return 86_400;   // at most once a day per user
    }

    public function handle(InferHomeZone $infer): void
    {
        $infer($this->userId);
    }
}
