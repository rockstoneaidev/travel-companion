<?php

declare(strict_types=1);

namespace App\Domain\Trips\Queries;

use App\Domain\Trips\Models\ExploreSession;

/**
 * Where the user last stood — the anchor for the home map.
 *
 * NOT a location fix. Phase 1 is foreground-only and pull-based (PRD §8): the app
 * does not know where you are unless you have opened a session and told it. So this
 * returns the origin of the most recent session, whatever its status, which is the
 * last place the user *chose* to be treated as "here".
 *
 * That distinction matters on the home screen. The map is oriented around a place
 * the user themselves nominated, not around a position we quietly kept tracking —
 * and when there has never been a session, it returns null and the screen says so
 * rather than centring on a guess.
 */
final class FindLastKnownOriginForUser
{
    /** @return array{lat: float, lng: float}|null */
    public function __invoke(int $userId): ?array
    {
        $session = ExploreSession::query()
            ->where('user_id', $userId)
            ->latest('started_at')
            ->first();

        /*
         * A session with no origin is a session we have DELIBERATELY forgotten the origin
         * of: `origin` is nullable and two things null it on purpose — the privacy erase
         * (EraseTripLocations) and the 30-day retention pass, which coarsens the coordinate
         * to its H3 cell on the way to deleting it (PRD §16).
         *
         * Only "no session at all" used to be guarded, so the first user to exercise their
         * right to erasure would have got a 500 on the home screen — punished, by a crash,
         * for using the privacy feature. Forgetting is a state this query has to be able to
         * return: it lands on the same honest null as "you have never started one", and the
         * screen simply draws no map.
         */
        if ($session?->origin === null) {
            return null;
        }

        return ['lat' => (float) $session->origin->lat, 'lng' => (float) $session->origin->lng];
    }
}
