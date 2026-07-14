<?php

declare(strict_types=1);

namespace App\Domain\Context\Data;

use App\Domain\Places\Data\Coordinates;
use Carbon\CarbonImmutable;

/**
 * A single reported position, with the two things a consumer needs in order to
 * trust it: when it was taken, and how precise the device claimed to be.
 *
 * The accuracy matters to E46 specifically. A phone that reports "you are here,
 * ±800 m" has not told you the user moved 500 m — it has told you it does not
 * know where they are. Re-ranking the feed on that is churn dressed up as
 * responsiveness, so the drift test discounts it (see SessionAnchor).
 */
final readonly class PositionFix
{
    public function __construct(
        public Coordinates $at,
        public CarbonImmutable $occurredAt,
        public ?int $accuracyMeters,
    ) {}
}
