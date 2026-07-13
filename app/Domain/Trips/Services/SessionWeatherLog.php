<?php

declare(strict_types=1);

namespace App\Domain\Trips\Services;

use App\Domain\Context\Data\WeatherContext;
use App\Domain\Trips\Models\ExploreSession;

/**
 * The sky a session was ranked under, written down once and kept (docs/PRD §16, S7).
 *
 * This lives in Trips rather than in Recommendations because the session is Trips'
 * row, and a module does not reach into another module's models (conventions/01). The
 * ranker calls it through this service exactly as it already calls `HomeZone` — one
 * module's fact, exposed by its owner.
 */
final class SessionWeatherLog
{
    /**
     * FIRST observation wins.
     *
     * A session re-read at 6pm must not overwrite the sky it actually started under at
     * 2pm: the snapshot is the weather we DECIDED under, not whatever happened to be
     * current the last time somebody opened the app. `whereNull('weather')` is that
     * rule, enforced in the WHERE clause rather than in a read-then-write that two
     * concurrent feed requests could interleave.
     *
     * An unknown sky is not written at all. Four nulls in a jsonb column look exactly
     * like a snapshot, and "we looked and saw nothing" is a claim we cannot support —
     * so absence stays absent, and `known()` stays the one test for it.
     */
    public function record(string $sessionId, WeatherContext $weather): void
    {
        if (! $weather->known()) {
            return;
        }

        ExploreSession::query()
            ->whereKey($sessionId)
            ->whereNull('weather')
            ->update([
                'weather' => $weather->toTrace(),
                'weather_observed_at' => now(),
            ]);
    }
}
