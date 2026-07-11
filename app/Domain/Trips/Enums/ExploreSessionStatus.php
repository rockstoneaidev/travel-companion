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
 * - `expired` — the time budget elapsed and nobody ended it. Written by the
 *   reaper, which reads `expires_at` and nothing else (conventions/03).
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
