<?php

declare(strict_types=1);

namespace App\Domain\Trips\Queries;

use App\Domain\Context\Enums\ContextSource;
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
            /*
             * Never the emulator's session (ADMIN §6, E47).
             *
             * An operator emulating a walk through Hornstull has an active session that
             * belongs to a pin, not to them. Without this line, opening /explore in
             * another tab would hand them that session as though it were their own
             * afternoon — and every tap they made in it would be recorded against a
             * position they were never standing in.
             *
             * The emulator reaches its session by id, from the console. This query is
             * the one that answers "where was *I*", and the answer is never "in the
             * simulation".
             */
            ->where('context_source', ContextSource::Device)
            ->with('trip')
            ->latest('started_at')
            ->first();
    }
}
