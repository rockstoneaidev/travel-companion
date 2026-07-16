<?php

declare(strict_types=1);

namespace App\Domain\Trips\Data;

use Carbon\CarbonImmutable;

/**
 * `PATCH /api/v1/trips/{trip}` — rename, mark ended (PRD §14.5). Nothing else:
 * a client cannot move a trip back to `active`, because that would race the
 * one-active-trip-per-user invariant that the implicit clustering owns.
 */
final readonly class TripUpdateData
{
    public function __construct(
        public ?string $name = null,
        public bool $complete = false,
        public ?CarbonImmutable $plannedStartAt = null,
        public ?CarbonImmutable $departsAt = null,
        public bool $datesProvided = false,
    ) {}
}
