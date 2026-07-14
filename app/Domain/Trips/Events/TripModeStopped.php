<?php

declare(strict_types=1);

namespace App\Domain\Trips\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody just took that permission back (E29).
 *
 * Emitted from day one with nothing listening, exactly as `ExploreSessionStarted` was —
 * the corridor cache and any pending pushes must be torn down when this fires, and
 * a mode that stops quietly is a mode nobody believes is off.
 */
final class TripModeStopped
{
    use Dispatchable;

    public function __construct(
        public readonly string $tripId,
        public readonly int $userId,
    ) {}
}
