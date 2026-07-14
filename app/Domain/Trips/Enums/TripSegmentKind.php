<?php

declare(strict_types=1);

namespace App\Domain\Trips\Enums;

/**
 * The tempo of a day (PRD §6.6, E38) — inferred, never declared.
 *
 * Three kinds, and the boundaries between them are movement, not mood. We cannot see
 * mood. We can see that somebody ended the day 300 km from where they started, and that
 * is a travel day whatever they felt about it.
 */
enum TripSegmentKind: string
{
    /** You ended the day somewhere else. The day *was* the journey — this is also the route-leg. */
    case Travel = 'travel';

    /** You stayed in one region and covered a lot of it. */
    case Sightseeing = 'sightseeing';

    /** You barely moved. A beach, a hangover, a working day, rain. We do not need to know which. */
    case Relaxation = 'relaxation';

    /**
     * What the day's tempo implies about interrupting it.
     *
     * Not used to gate anything (the gates are NotificationPolicy's, and they are hard) —
     * this is the honest observation that a person driving 400 km has less appetite for a
     * detour than a person who has spent the day walking around a town, and a person doing
     * nothing on purpose may not want to be told about a 40-minute museum at all.
     */
    public function detourAppetite(): float
    {
        return match ($this) {
            self::Sightseeing => 1.0,   // they came here to look at things
            self::Travel => 0.6,        // a stop must be worth breaking the drive for
            self::Relaxation => 0.4,    // resting is a legitimate plan, and we are not it
        };
    }
}
