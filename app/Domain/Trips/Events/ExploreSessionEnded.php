<?php

declare(strict_types=1);

namespace App\Domain\Trips\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** PRD §10 event vocabulary. */
final class ExploreSessionEnded
{
    use Dispatchable;

    public function __construct(
        public readonly string $exploreSessionId,
        public readonly string $tripId,
        public readonly int $userId,
    ) {}
}
