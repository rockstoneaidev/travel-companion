<?php

declare(strict_types=1);

namespace App\Domain\Trips\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Trip lifecycle (PRD §6.6): planned → active → completed. "Active" begins at
 * the first explore session; "completed" on inactivity or explicitly.
 *
 * `planned` exists only for the optional explicit planner path (a named trip
 * pre-created to enable pre-scouting). The implicit path opens the trip
 * directly as `active`.
 *
 * At most one `active` trip per user — enforced in the domain
 * (ResolveOrCreateTripForSession) and by a partial unique index in the
 * database (conventions/03).
 */
enum TripStatus: string
{
    use HasOptions;

    case Planned = 'planned';
    case Active = 'active';
    case Completed = 'completed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** A live trip is one that new sessions may attach to. */
    public function isLive(): bool
    {
        return $this === self::Active;
    }

    /** @return list<self> */
    public static function terminal(): array
    {
        return [self::Completed];
    }
}
