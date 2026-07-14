<?php

declare(strict_types=1);

namespace App\Domain\Trips\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody just gave us permission to follow them (E29).
 *
 * Emitted from day one with nothing listening, exactly as `ExploreSessionStarted` was —
 * the route-corridor scout (E35) and the notification engine (E30) hang off this, and
 * emitting it now is what makes them additive rather than a rewrite.
 */
final class TripModeStarted
{
    use Dispatchable;

    public function __construct(
        public readonly string $tripId,
        public readonly int $userId,
    ) {}
}
