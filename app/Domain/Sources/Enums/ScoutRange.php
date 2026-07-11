<?php

declare(strict_types=1);

namespace App\Domain\Sources\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The payoff gradient (conventions/09): a café 30 km ahead is noise; a ruined
 * castle is worth the drive. Read by the scout runner together with the
 * coverage geometry (conventions/12).
 */
enum ScoutRange: string
{
    use HasOptions;

    /** Queried across the session's entire coverage, all modes. */
    case Full = 'full';

    /** Queried only in the near ring (walking-scale radius around origin/destination). */
    case Near = 'near';
}
