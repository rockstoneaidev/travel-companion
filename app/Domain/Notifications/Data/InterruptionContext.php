<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Data;

use App\Domain\Context\Enums\MovementMode;
use Carbon\CarbonImmutable;

/**
 * What is true about the person, right now (PRD §12.2).
 *
 * The other half of a pure policy: state in, decision out. Nothing here is fetched by the
 * policy itself — it is assembled once, by the action, and handed over. That is what makes
 * "replay this day under policy_v3" a thing you can actually do.
 */
final readonly class InterruptionContext
{
    public function __construct(
        public int $userId,
        public ?string $tripId,
        public bool $inTripMode,
        public CarbonImmutable $at,

        /** Their local hour — quiet hours are a fact about a person, not about UTC. */
        public int $localHour,
        public ?int $quietHoursStart,
        public ?int $quietHoursEnd,
        public ?int $maxDetourMinutes,

        public ?MovementMode $movementMode,

        /** Sent today, and when the last one went. The budget, straight from the ledger. */
        public int $sentToday,
        public ?CarbonImmutable $lastSentAt,

        /** Type-domains this user has dismissed recently. Asking again is not persistence. */
        public array $recentlyRejectedDomains = [],

        /** How many pushes they have had in the last few hours — the interruption penalty. */
        public int $sentRecently = 0,
    ) {}
}
