<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Data;

use App\Domain\Places\Data\Coordinates;
use App\Domain\Recommendations\Enums\ServeReason;
use Carbon\CarbonImmutable;

/**
 * Which batch the feed on screen actually is (E46).
 *
 * The client needs this for one honest reason: to be able to say "you've moved —
 * these are updated picks" without guessing. It compares the group number it last
 * rendered against the one it just received; if the number went up and the reason
 * was a move, the user is looking at a different menu than the one they walked away
 * from, and the interface should say so rather than silently swapping the cards.
 *
 * The anchor is included because the map draws it: "picks from here" is a claim
 * about a point, and the point should be the one we actually ranked from.
 */
final readonly class ServeData
{
    public function __construct(
        public int $group,
        public ServeReason $reason,
        public ?Coordinates $anchor,
        public ?CarbonImmutable $servedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'group' => $this->group,
            'reason' => $this->reason->value,
            'anchor' => $this->anchor?->toArray(),
            'served_at' => $this->servedAt?->toIso8601String(),
        ];
    }
}
