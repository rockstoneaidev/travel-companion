<?php

declare(strict_types=1);

namespace App\Domain\Trips\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * PRD §10 event vocabulary. The scouts (E5) listen to this — "everything
 * interesting happens because of events, not synchronous API requests".
 */
final class ExploreSessionStarted
{
    use Dispatchable;

    public function __construct(
        public readonly string $exploreSessionId,
        public readonly string $tripId,
        public readonly int $userId,
    ) {}
}
