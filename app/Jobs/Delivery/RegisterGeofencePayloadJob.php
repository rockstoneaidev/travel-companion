<?php

declare(strict_types=1);

namespace App\Jobs\Delivery;

use App\Domain\Notifications\Services\BuildCorridorPayload;
use App\Domain\Trips\Models\Trip;
use App\Enums\QueueLane;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Pre-build a trip's offline geofence bundle (E36; PRD §14.1).
 *
 * Thin wrapper (conventions/08). Named exactly as PRD §14.1 names it. Builds the corridor
 * payload ahead of time and caches it, so the device's pre-download request is a cache read
 * rather than a live scout — the phone is often about to lose signal, and the download must
 * be quick and cheap.
 *
 * Unique per trip for a few minutes: the corridor does not change faster than the ingest
 * behind it, and rebuilding it on every ping would be waste.
 */
final class RegisterGeofencePayloadJob implements ShouldBeUnique, ShouldQueue
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

    public function handle(BuildCorridorPayload $build): void
    {
        $trip = Trip::query()->find($this->tripId);

        if ($trip === null) {
            return;
        }

        $anchor = $trip->anchor_point;
        Cache::put("corridor-payload:{$this->tripId}", $build($anchor?->lat, $anchor?->lng), now()->addHour());
    }
}
