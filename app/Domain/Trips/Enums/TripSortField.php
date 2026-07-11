<?php

declare(strict_types=1);

namespace App\Domain\Trips\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The sort whitelist for `GET /api/v1/trips` (conventions/07): `sort_by` is an
 * enum, never a free string reaching orderBy().
 */
enum TripSortField: string
{
    use HasOptions;

    case StartedAt = 'started_at';
    case LastSessionAt = 'last_session_at';
    case CreatedAt = 'created_at';
    case Name = 'name';

    /** Maps the public sort name to the actual column. They are allowed to differ. */
    public function column(): string
    {
        return match ($this) {
            self::StartedAt => 'started_at',
            self::LastSessionAt => 'last_session_at',
            self::CreatedAt => 'created_at',
            self::Name => 'name',
        };
    }
}
