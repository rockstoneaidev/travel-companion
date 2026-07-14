<?php

declare(strict_types=1);

namespace App\Jobs\Ranking;

use App\Domain\Feedback\Actions\DetectVisits;
use App\Enums\QueueLane;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Look for visits in what the phone just told us (E37). Thin wrapper (conventions/08).
 *
 * Unique per trip for five minutes, like the segment inference it rides alongside: a dwell
 * that has lasted ten minutes will still be there in five, and re-scanning a trip on every
 * single ping would turn the cheapest event in the system into the most expensive one.
 */
final class DetectVisitsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $tripId)
    {
        $this->onQueue(QueueLane::Default->value);
    }

    public function uniqueId(): string
    {
        return $this->tripId;
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    public function handle(DetectVisits $detect): void
    {
        $detect($this->tripId);
    }
}
