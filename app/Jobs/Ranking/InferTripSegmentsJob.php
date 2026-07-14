<?php

declare(strict_types=1);

namespace App\Jobs\Ranking;

use App\Domain\Trips\Actions\InferTripSegments;
use App\Enums\QueueLane;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-read the shape of a trip (E38). Thin wrapper (conventions/08).
 *
 * Runs off the back of context ingestion rather than on a nightly cron, because the value
 * of knowing that today is a travel day expires at midnight. `ShouldBeUnique` per trip for
 * five minutes: a Trip Mode ping every few hundred metres must not re-classify the entire
 * trip every few hundred metres.
 */
final class InferTripSegmentsJob implements ShouldBeUnique, ShouldQueue
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

    public function handle(InferTripSegments $infer): void
    {
        $infer($this->tripId);
    }
}
