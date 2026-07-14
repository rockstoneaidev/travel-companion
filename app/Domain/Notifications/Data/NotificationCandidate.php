<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Data;

use Carbon\CarbonImmutable;

/**
 * One thing we are considering interrupting somebody about (PRD §12.2).
 *
 * Everything the policy needs, and nothing it does not. It holds no model, reads no
 * database, and asks no service — which is what makes the policy a pure function of its
 * inputs, and therefore replayable under a different version (§12.2's whole point).
 */
final readonly class NotificationCandidate
{
    public function __construct(
        public string $recommendationId,
        public ?string $opportunityId,
        public string $title,

        // Sub-scores, straight off the recommendation's trace (SCORING §2).
        public float $confidence,
        public float $urgency,
        public float $personalFit,
        public float $uniqueness,
        public float $composite,

        public ?int $detourMinutes,
        public bool $openNow,
        /** Null when the source knows of no hours — which is not "closed" (E16). */
        public ?CarbonImmutable $windowEndsAt,
        public float $evidenceAgeDays,

        /** The taxonomy Type-axis domain, for the "recently rejected" gate. */
        public ?string $typeDomain,

        /**
         * May this source be PUSHED, not merely shown? (conventions/09.)
         *
         * Some feeds licence display in-app and nothing else. A licence breach is not a
         * growth tactic, and the gate exists so nobody has to remember that at 2am.
         */
        public bool $pushable = true,
    ) {}
}
