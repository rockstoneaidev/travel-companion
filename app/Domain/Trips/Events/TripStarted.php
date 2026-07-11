<?php

declare(strict_types=1);

namespace App\Domain\Trips\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** PRD §10 event vocabulary. Carries ids, never models — listeners live in other modules. */
final class TripStarted
{
    use Dispatchable;

    public function __construct(
        public readonly string $tripId,
        public readonly int $userId,
    ) {}
}
