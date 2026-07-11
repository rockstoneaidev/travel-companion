<?php

declare(strict_types=1);

namespace App\Domain\Trips\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How the trip came into existence (PRD §6.6). Both paths converge on the same
 * entity; this records which one opened it.
 *
 * - `auto` — materialised by the implicit clustering when a session started and
 *   no live trip was close enough in space and time.
 * - `user` — pre-created by a planner (`POST /api/v1/trips`) to enable
 *   pre-scouting.
 */
enum TripSource: string
{
    use HasOptions;

    case Auto = 'auto';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Automatic',
            self::User => 'User-created',
        };
    }
}
