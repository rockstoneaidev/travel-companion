<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The reason we did NOT interrupt somebody (PRD §12.2).
 *
 * Every gate is a hard one — all must pass — and every denial is written down. The
 * interesting half of a notification policy is the notifications it did not send, and a
 * policy that cannot say *which gate* stopped a push cannot answer the one question PRD
 * §12.2 exists to make askable: "would policy_v3 have avoided the annoying push that
 * policy_v2 sent?"
 */
enum NotificationGate: string
{
    use HasOptions;

    /** "No passive companionship unless the user turns it on" (PRD §16). The first gate. */
    case TripModeOff = 'trip_mode_off';

    /** Their quiet hours. A companion that wakes you at 03:00 is not a companion. */
    case QuietHours = 'quiet_hours';

    /**
     * Driving. Phase 1 has no voice mode, so there is no safe way to say this — and a
     * notification that makes someone look at a phone at 90 km/h is not a product
     * decision, it is a hazard.
     */
    case Driving = 'driving';

    /** Too soon after the last one (§12.2: max 1 per 60–90 minutes). */
    case Cooldown = 'cooldown';

    /** The hard ceiling: 3 proactive pushes a day (CLAUDE.md non-negotiable #4). */
    case DailyBudget = 'daily_budget';

    /** We are not sure enough of it to be worth someone's attention. */
    case LowConfidence = 'low_confidence';

    /** Shut, or outside its window. "Go now" about a closed door is worse than silence. */
    case NotOpen = 'not_open';

    /** Further out of their way than they said they would go. */
    case DetourTooFar = 'detour_too_far';

    /** The evidence behind it is too old to stand behind. */
    case StaleEvidence = 'stale_evidence';

    /** They have said no to this kind of thing recently. Asking again is not persistence. */
    case CategoryRejected = 'category_rejected';

    /**
     * The source's licence forbids pushing it (conventions/09).
     *
     * Not every fact we may SHOW may be SENT — some feeds permit display in-app and
     * nothing else. A licence breach is not a growth tactic.
     */
    case NotPushable = 'not_pushable';

    public function label(): string
    {
        return match ($this) {
            self::TripModeOff => 'Trip Mode is off',
            self::QuietHours => 'Inside their quiet hours',
            self::Driving => 'Driving',
            self::Cooldown => 'Too soon after the last push',
            self::DailyBudget => 'Daily budget spent',
            self::LowConfidence => 'Not confident enough',
            self::NotOpen => 'Not open right now',
            self::DetourTooFar => 'Further than they will go',
            self::StaleEvidence => 'Evidence too old',
            self::CategoryRejected => 'They recently said no to this kind',
            self::NotPushable => 'Source licence forbids pushing',
        };
    }
}
