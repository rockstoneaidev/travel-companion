<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Data\InterruptionContext;
use App\Domain\Notifications\Data\NotificationCandidate;
use App\Domain\Notifications\Data\NotificationDecision;
use App\Domain\Notifications\Enums\NotificationGate;

/**
 * WHETHER to interrupt somebody. Deterministic, versioned, and not a model (PRD §12.2).
 *
 * ===========================================================================
 *  NON-NEGOTIABLE #4: THE LLM NEVER DECIDES WHEN TO INTERRUPT.
 * ===========================================================================
 *
 * This class is the entire meaning of that sentence. A language model may write the words
 * of a push ("the market near you closes in 22 minutes"); it may not choose the moment.
 * The moment is chosen here, by plain PHP with no I/O, from inputs handed to it — which is
 * also what makes it *replayable*: PRD §12.2 wants to be able to ask, offline, "would
 * policy_v3 have avoided the annoying push that policy_v2 sent?" You cannot ask that of a
 * model. You can ask it of a function.
 *
 * PRD risk 5, stated plainly: *notification fatigue destroys the product's core promise.*
 * The promise is that when this thing speaks, it is worth hearing. Every gate below exists
 * because one bad push costs more than ten good ones earn.
 *
 * ## The shape
 *
 *   HARD GATES  — all must pass. Any failure denies, and the gate is written down.
 *   BUDGET      — 3 a day, one an hour. The urgent exception relaxes the HOUR, never the DAY.
 *   SOFT BOOSTS — order the survivors. They never rescue a candidate the gates refused.
 *
 * The separation is the design. A boost that could open a gate would mean a sufficiently
 * exciting café could wake you at 3am, and no amount of excitement makes that acceptable.
 */
final class NotificationPolicy
{
    /**
     * Bump on ANY behavioural change, and mean it.
     *
     * Every decision row records this. It is what makes the counterfactual answerable, and
     * a version that quietly covers two behaviours makes every historical answer a lie.
     */
    public const VERSION = 'v1';

    public function decide(NotificationCandidate $candidate, InterruptionContext $context): NotificationDecision
    {
        $trace = [];

        foreach ($this->gates($candidate, $context) as $gate => $passed) {
            $trace['gates'][$gate] = $passed;

            if (! $passed) {
                return NotificationDecision::denied(NotificationGate::from($gate), self::VERSION, $trace);
            }
        }

        /*
         * THE BUDGET (PRD §12.2, CLAUDE.md non-negotiable #4).
         *
         * Three a day. Not "three unless something is really good" — three. The urgent
         * exception below relaxes the COOLDOWN, never the daily cap, and that asymmetry is
         * deliberate: a cooldown protects a moment, a daily cap protects a relationship.
         */
        $urgent = $this->urgentException($candidate, $context);
        $trace['urgent_exception'] = $urgent;

        if ($context->sentToday >= (int) config('notifications.budget.max_per_day')) {
            return NotificationDecision::denied(NotificationGate::DailyBudget, self::VERSION, $trace);
        }

        if (! $urgent && $this->inCooldown($context)) {
            return NotificationDecision::denied(NotificationGate::Cooldown, self::VERSION, $trace);
        }

        $boosts = $this->boosts($candidate, $context);
        $penalty = $this->interruptionPenalty($context);

        $trace['boosts'] = $boosts;
        $trace['interruption_penalty'] = $penalty;

        /*
         * Priority ORDERS the allowed set. It does not gate it (SCORING §5.3, verbatim:
         * "it only *orders* candidates within the set the deterministic notification policy
         * already allowed — it never replaces those gates").
         */
        $priority = $candidate->composite + array_sum($boosts) - $penalty;

        return NotificationDecision::allowed(self::VERSION, round($priority, 4), $trace);
    }

    /**
     * The hard gates. ALL must pass (PRD §12.2).
     *
     * Ordered cheapest-and-most-absolute first, so the denial we record is the most
     * *explanatory* one: "you were driving" is a better answer than "the evidence was 9
     * days old", even when both are true.
     *
     * @return array<string, bool>
     */
    private function gates(NotificationCandidate $candidate, InterruptionContext $context): array
    {
        return [
            NotificationGate::TripModeOff->value => $context->inTripMode,

            // A companion that wakes you at 03:00 is not a companion.
            NotificationGate::QuietHours->value => ! $this->inQuietHours($context),

            /*
             * Driving. There is no voice mode in Phase 2, so there is no safe way to say
             * this — and a notification that makes somebody look at a phone at 90 km/h is
             * not a product decision, it is a hazard. PRD §12.2 allows the exception only
             * "unless voice mode", and voice mode does not exist.
             */
            NotificationGate::Driving->value => $context->movementMode?->value !== 'driving',

            // Licence before growth (conventions/09). Some sources may be shown and not sent.
            NotificationGate::NotPushable->value => $candidate->pushable,

            NotificationGate::LowConfidence->value => $candidate->confidence >= (float) config('notifications.gates.min_confidence'),

            /*
             * "Currently open/available" (PRD §12.2). Note the null case: unknown hours are
             * NOT closed — most of the OSM long tail has no hours anywhere, and treating
             * silence as "shut" would delete exactly the layer this product exists to
             * surface (E16). We push it; we simply do not claim it is open.
             */
            NotificationGate::NotOpen->value => $candidate->openNow,

            NotificationGate::DetourTooFar->value => $this->withinDetour($candidate, $context),

            NotificationGate::StaleEvidence->value => $candidate->evidenceAgeDays <= (float) config('notifications.gates.max_evidence_age_days'),

            // They said no to this kind of thing recently. Asking again is not persistence,
            // it is not listening.
            NotificationGate::CategoryRejected->value => $candidate->typeDomain === null
                || ! in_array($candidate->typeDomain, $context->recentlyRejectedDomains, true),
        ];
    }

    /**
     * The urgent exception, EXACTLY as PRD §12.2 writes it:
     *
     *     confidence > 0.85 AND urgency > 0.85 AND personal_fit > 0.75 AND detour < threshold
     *
     * All four. It buys one thing and one thing only: the right to ignore the cooldown. It
     * does not buy the daily cap, it does not buy quiet hours, and it certainly does not
     * buy driving.
     */
    private function urgentException(NotificationCandidate $candidate, InterruptionContext $context): bool
    {
        $t = (array) config('notifications.urgent');

        return $candidate->confidence > (float) $t['min_confidence']
            && $candidate->urgency > (float) $t['min_urgency']
            && $candidate->personalFit > (float) $t['min_personal_fit']
            && $this->withinDetour($candidate, $context);
    }

    private function inCooldown(InterruptionContext $context): bool
    {
        if ($context->lastSentAt === null) {
            return false;
        }

        return $context->lastSentAt->diffInMinutes($context->at, absolute: true)
            < (int) config('notifications.budget.cooldown_minutes');
    }

    /**
     * Quiet hours, across midnight.
     *
     * 22 → 08 is the normal shape of "don't wake me", and it wraps. Writing it as a naive
     * `start <= hour && hour < end` would make the most common setting the one that never
     * fires, which is the sort of bug that only shows up at 3am, in production, to a user.
     */
    private function inQuietHours(InterruptionContext $context): bool
    {
        $start = $context->quietHoursStart ?? (int) config('notifications.quiet_hours.default_start');
        $end = $context->quietHoursEnd ?? (int) config('notifications.quiet_hours.default_end');

        if ($start === $end) {
            return false;   // no quiet hours at all
        }

        return $start < $end
            ? $context->localHour >= $start && $context->localHour < $end
            : $context->localHour >= $start || $context->localHour < $end;   // wraps midnight
    }

    private function withinDetour(NotificationCandidate $candidate, InterruptionContext $context): bool
    {
        if ($candidate->detourMinutes === null) {
            return true;   // we do not know the detour; we do not invent one to refuse on
        }

        $tolerance = $context->maxDetourMinutes ?? (int) config('notifications.gates.default_max_detour_minutes');

        return $candidate->detourMinutes <= $tolerance;
    }

    /**
     * Soft boosts (PRD §12.2). They ORDER; they never gate.
     *
     * @return array<string, float>
     */
    private function boosts(NotificationCandidate $candidate, InterruptionContext $context): array
    {
        $weights = (array) config('notifications.boosts');
        $boosts = [];

        // Time-sensitive: the whole reason a push beats the digest. "Closes in 22 minutes"
        // is information that expires; a nice park is not.
        if ($candidate->urgency > 0.5) {
            $boosts['time_sensitive'] = (float) $weights['time_sensitive'] * $candidate->urgency;
        }

        // Matches a strong preference.
        if ($candidate->personalFit > 0.7) {
            $boosts['strong_preference'] = (float) $weights['strong_preference'] * $candidate->personalFit;
        }

        // Rare/unique — the thing they would have missed, which is the product's promise.
        if ($candidate->uniqueness > 0.7) {
            $boosts['rare'] = (float) $weights['rare'] * $candidate->uniqueness;
        }

        // Last chance before the window shuts.
        if ($candidate->windowEndsAt !== null
            && $candidate->windowEndsAt->diffInMinutes($context->at, absolute: true) <= (int) config('notifications.boosts.last_chance_minutes')) {
            $boosts['last_chance'] = (float) $weights['last_chance'];
        }

        return $boosts;
    }

    /**
     * `interruption_penalty` goes live (SCORING §5.3).
     *
     * Weight 0.20, `raw ≡ 0` in Phase 1 — it has been a stub in the scoring model since the
     * beginning, waiting for a Phase in which anything could interrupt. This is that Phase.
     *
     * The driver that matters most is NOTIFICATION DENSITY: the third push of an afternoon
     * is worth less than the first, whatever it says, because the cost of an interruption is
     * not a property of the thing interrupting — it is a property of how recently you were
     * last interrupted. Penalising density is how the policy stops treating its budget as a
     * quota to spend.
     */
    private function interruptionPenalty(InterruptionContext $context): float
    {
        $weight = (float) config('notifications.interruption_penalty.weight');
        $perRecent = (float) config('notifications.interruption_penalty.per_recent_push');

        return round($weight * min(1.0, $context->sentRecently * $perRecent), 4);
    }
}
