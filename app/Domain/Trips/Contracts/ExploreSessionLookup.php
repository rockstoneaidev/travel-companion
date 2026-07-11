<?php

declare(strict_types=1);

namespace App\Domain\Trips\Contracts;

use App\Domain\Trips\Data\ExploreSessionData;

/**
 * What Trips publishes about a session (conventions/01). The Context module
 * needs to know a session exists, who owns it, which trip it belongs to and
 * whether it is still live — and must get that without touching Trips' models.
 */
interface ExploreSessionLookup
{
    public function find(string $exploreSessionId): ?ExploreSessionData;
}
