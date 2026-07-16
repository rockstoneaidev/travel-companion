<?php

declare(strict_types=1);

namespace App\Domain\Trips\Data;

use App\Domain\Places\Data\Coordinates;
use Carbon\CarbonImmutable;

/**
 * The optional explicit path (PRD §6.6): a planner pre-creates a named trip to
 * enable pre-scouting. It opens as `planned`, not `active` — "active" begins at
 * the first session, and the one-active-trip-per-user invariant belongs to the
 * implicit path.
 */
final readonly class NewTripData
{
    public function __construct(
        public int $userId,
        public string $name,
        public ?Coordinates $anchorPoint = null,
        public ?CarbonImmutable $plannedStartAt = null,
        public ?CarbonImmutable $departsAt = null,
    ) {}
}
