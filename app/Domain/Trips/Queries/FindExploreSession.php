<?php

declare(strict_types=1);

namespace App\Domain\Trips\Queries;

use App\Domain\Trips\Contracts\ExploreSessionLookup;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;

final class FindExploreSession implements ExploreSessionLookup
{
    public function find(string $exploreSessionId): ?ExploreSessionData
    {
        $session = ExploreSession::query()->find($exploreSessionId);

        return $session === null ? null : ExploreSessionData::fromModel($session);
    }
}
