<?php

declare(strict_types=1);

namespace App\Domain\Trips\Services;

use App\Domain\Trips\Models\Trip;
use Carbon\CarbonImmutable;

/**
 * `last_feasible_start`, when we know when you're leaving (E38; SCORING §4.3).
 *
 * ## The two horizons
 *
 * SCORING §4.3 says urgency is driven by one quantity — slack until the last feasible
 * start — and that the horizon bounding it is *phase-dependent*:
 *
 *   **Phase 1** (no known departure): the horizon is end of the session's day. Urgency is
 *   therefore within-day closing urgency. A park that never closes gets low urgency
 *   because there is nothing to be urgent about before bedtime.
 *
 *   **Stay-aware** (departure known): the horizon is *the last chance before you leave the
 *   region*. And this is where the two behaviours the product has always wanted stop
 *   being features and start being consequences:
 *
 *   - **Evergreen slack ≈ length of stay.** You have four days; the cathedral is not
 *     urgent, and the feed stops shouting about a building that has stood for 800 years
 *     and will stand for 800 more. Nothing special-cases "evergreen" — the slack is just
 *     genuinely large.
 *
 *   - **The last day makes everything urgent.** Not a rule anybody wrote. On the final
 *     morning the horizon is hours away, so every slack collapses and every decay term
 *     spikes together. The product's most emotionally correct behaviour — *"this is your
 *     last chance"* — is a subtraction.
 *
 * ## Why the departure must never be guessed silently
 *
 * This horizon is load-bearing for every urgency score on the trip. A departure we made up
 * would make the whole feed shout on a day the traveller has a week left, and there is no
 * user-visible symptom pointing back at the cause. So: `departs_at` is nullable, `NULL`
 * means *we do not know*, and not knowing falls back to the Phase 1 horizon rather than to
 * an estimate. `departure_source` records whether a human said it or we inferred it.
 */
final class StayHorizon
{
    /**
     * The last moment it is still feasible to start this opportunity.
     *
     * @param  CarbonImmutable  $dayHorizon  the Phase 1 answer: end of the session's day
     * @param  ?CarbonImmutable  $closesAt  this candidate's own closing today (hours, daylight), if known
     */
    public function lastFeasibleStart(
        ?string $tripId,
        CarbonImmutable $at,
        CarbonImmutable $dayHorizon,
        ?CarbonImmutable $closesAt,
    ): CarbonImmutable {
        $departsAt = $tripId === null ? null : $this->departure($tripId);

        // Phase 1, and the common case: no declared departure, so we bound at the day.
        if ($departsAt === null || $departsAt->lessThanOrEqualTo($at)) {
            return $this->earliest($dayHorizon, $closesAt);
        }

        /*
         * Departure known.
         *
         * If the candidate closes today, that closing does NOT end its story — it reopens
         * tomorrow, and you are still here tomorrow. The real last chance is the same
         * closing time on the LAST DAY you are in the region.
         *
         * So we roll the closing forward to the departure date, then bound by the departure
         * itself. On the final day those two collapse into each other, and everything
         * becomes urgent without a single line of code that mentions "last day".
         */
        if ($closesAt === null) {
            return $departsAt;   // evergreen: your only deadline is the flight
        }

        $lastChance = $departsAt
            ->setTime((int) $closesAt->format('H'), (int) $closesAt->format('i'));

        // If it shuts after you have already gone, your deadline is the going.
        return $this->earliest($departsAt, $lastChance);
    }

    private function departure(string $tripId): ?CarbonImmutable
    {
        /** @var ?Trip $trip */
        $trip = Trip::query()->find($tripId, ['id', 'departs_at']);

        return $trip?->departs_at;
    }

    private function earliest(CarbonImmutable $a, ?CarbonImmutable $b): CarbonImmutable
    {
        return $b !== null && $b->lessThan($a) ? $b : $a;
    }
}
