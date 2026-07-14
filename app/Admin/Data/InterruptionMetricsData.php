<?php

declare(strict_types=1);

namespace App\Admin\Data;

/**
 * The answer to MVP question 3 — "can we interrupt at the right time?" (PRD §7.2, E44) —
 * as far as the data can currently answer it.
 *
 * Every field is a RATE with its denominator alongside, never a bare percentage. "80%
 * acceptance" over five pushes is not a finding, it is a coincidence, and a dashboard that
 * hides its n invites exactly that mistake. The page shows both.
 */
final readonly class InterruptionMetricsData
{
    public function __construct(
        // What the policy DECIDED.
        public int $considered,       // candidates the policy judged
        public int $allowed,          // ...and let through
        public float $silenceRate,    // denied / considered — HIGH is good; restraint is the product

        // What happened to the ones we sent.
        public int $sent,
        public int $opened,
        public int $dismissed,        // swiped away
        public float $acceptanceRate, // opened / sent — the interruption was welcome
        public float $annoyanceRate,  // dismissed / sent — the moment was wrong

        /**
         * Why we stayed quiet, by gate. The interesting half of a notification policy is the
         * notifications it did NOT send (PRD §12.2), and this is that half, made legible.
         *
         * @var array<string, int> gate → times it was the decisive denial
         */
        public array $denialsByGate,

        /**
         * The sharpest annoyance signal we have. A person who turned Trip Mode ON and then
         * OFF again during a trip is telling us, with an action rather than a survey, that
         * the interruptions were not worth it (PRD §7.3 guardrail).
         */
        public int $tripModeStarted,
        public int $tripModeAbandoned,
        public float $abandonmentRate,

        // How saturated the budget is: DailyBudget denials mean we WANTED to interrupt more
        // than three times. Rising saturation is an early warning of an over-eager policy,
        // long before it shows up as annoyance.
        public int $budgetSaturatedDays,

        public string $range,
        public string $policyVersion,
    ) {}
}
