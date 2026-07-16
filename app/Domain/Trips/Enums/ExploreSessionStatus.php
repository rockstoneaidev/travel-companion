<?php

declare(strict_types=1);

namespace App\Domain\Trips\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The lifecycle of the atomic Phase 1 unit (PRD §6.6): the user opens a
 * session ("I have 3 hours from here"), it accumulates context and feedback,
 * and it ends.
 *
 * - `active`  — accepting context events; the feed is live.
 * - `ended`   — the user ended it (`POST /explore-sessions/{session}/end`).
 * - `expired` — nobody ended it and it was abandoned: no feed served for the idle
 *   window (`trips.session.idle_expiry_minutes`). Written by the reaper
 *   (ExpireStaleSessions). NOT tied to the time budget — that is a reach envelope,
 *   not a deadline, so a long-but-active session is not expired (conventions/03).
 */
enum ExploreSessionStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Ended = 'ended';
    case Expired = 'expired';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** A live session accepts context events and serves a feed. */
    public function isLive(): bool
    {
        return $this === self::Active;
    }

    /** @return list<self> */
    public static function terminal(): array
    {
        return [self::Ended, self::Expired];
    }
}
