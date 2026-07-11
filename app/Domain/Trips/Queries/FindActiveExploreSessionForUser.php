<?php

declare(strict_types=1);

namespace App\Domain\Trips\Queries;

use App\Domain\Trips\Enums\ExploreSessionStatus;
use App\Domain\Trips\Models\ExploreSession;

/**
 * The web app's "do I already have a session open?" — the Inertia explore page
 * either offers the start form or resumes.
 */
final class FindActiveExploreSessionForUser
{
    public function __invoke(int $userId): ?ExploreSession
    {
        return ExploreSession::query()
            ->where('user_id', $userId)
            ->where('status', ExploreSessionStatus::Active)
            ->with('trip')
            ->latest('started_at')
            ->first();
    }
}
