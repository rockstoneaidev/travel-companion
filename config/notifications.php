<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The interruption budget (PRD §12.2) — numbers, not adjectives
|--------------------------------------------------------------------------
|
| PRD risk 5, stated plainly: notification fatigue destroys the product's core promise.
| The promise is that when this thing speaks, it is worth hearing — and one bad push costs
| more than ten good ones earn.
|
| So the policy is deterministic PHP (NotificationPolicy) and these are the constants it
| reads. They are versioned with `NotificationPolicy::VERSION`: change a number here and
| you have changed the policy, and every decision row that claims `v1` becomes a lie about
| what `v1` did. Bump the version.
|
*/

return [

    'budget' => [
        /*
         * THREE. A DAY.
         *
         * CLAUDE.md non-negotiable #4, and it is not a starting point for negotiation: "max
         * 3 proactive pushes/day". Not "three unless something is really good" — the urgent
         * exception below relaxes the COOLDOWN, never this. A cooldown protects a moment; a
         * daily cap protects a relationship.
         */
        'max_per_day' => 3,

        // PRD §12.2: "max 1 per 60–90 minutes". The bottom of the range, because the cost of
        // being early is an uninstall and the cost of being late is a missed café.
        'cooldown_minutes' => 60,
    ],

    /*
    | The urgent exception, EXACTLY as PRD §12.2 writes it. All four, or it is not urgent.
    |
    | It buys one thing: the right to ignore the cooldown. It does not buy the daily cap, it
    | does not buy quiet hours, and it does not buy driving.
    */
    'urgent' => [
        'min_confidence' => 0.85,
        'min_urgency' => 0.85,
        'min_personal_fit' => 0.75,
    ],

    'gates' => [
        // Below this we are not sure enough of the thing to spend somebody's attention on it.
        'min_confidence' => 0.55,

        // Evidence older than this cannot be stood behind. "The market is on today" sourced
        // from a fortnight-old page is a guess wearing a fact's clothes.
        'max_evidence_age_days' => 7.0,

        // Until they tell us otherwise. Generous for a walk, meaningless for a drive — which
        // is fine, because we do not push while driving at all.
        'default_max_detour_minutes' => 20,
    ],

    'quiet_hours' => [
        // 22:00–08:00, and it WRAPS. A default that never fires would be worse than none:
        // it would look like a promise being kept.
        'default_start' => 22,
        'default_end' => 8,
    ],

    'boosts' => [
        // Ordering only, never gating. A boost that could open a gate would mean a
        // sufficiently exciting café could wake you at 3am.
        'time_sensitive' => 0.30,
        'strong_preference' => 0.20,
        'rare' => 0.20,
        'last_chance' => 0.15,

        // "Last chance before leaving the region" / before the window shuts.
        'last_chance_minutes' => 45,
    ],

    /*
    | SCORING §5.3's stub, switched on.
    |
    | The third push of an afternoon is worth less than the first, whatever it says: the
    | cost of an interruption is not a property of the thing interrupting, it is a property
    | of how recently you were last interrupted. Penalising density is how the policy stops
    | treating its budget as a quota to spend.
    */
    'interruption_penalty' => [
        'weight' => 0.20,               // SCORING §5.3's weight, unchanged
        'per_recent_push' => 0.5,       // two recent pushes ⇒ the penalty is at full weight
        'recent_window_hours' => 4,
    ],

];
