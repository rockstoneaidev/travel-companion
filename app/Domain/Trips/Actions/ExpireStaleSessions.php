<?php

declare(strict_types=1);

namespace App\Domain\Trips\Actions;

use App\Domain\Trips\Enums\ExploreSessionStatus;
use App\Domain\Trips\Events\ExploreSessionEnded;
use App\Domain\Trips\Models\ExploreSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The session reaper (conventions/03; ExploreSessionStatus::Expired). It ends sessions that
 * have plainly been abandoned — NOT sessions whose time budget elapsed.
 *
 * That distinction is the whole point. The budget is a reach envelope, not a countdown (see
 * RankSession::plan): a "3-hour" session can be explored for eight, because the traveller's
 * guessed hours were never a deadline. So expiring at `started_at + budget` would end a live
 * outing on a fictional clock — which is exactly what once left someone at Fjäderholmarna with
 * a feed frozen hours before they arrived. A session is over when it has been walked away from:
 * no feed served for `idle_expiry_minutes` (a whole quiet night). Starting a new session ends
 * the previous one directly, so this only ever sweeps up the truly-orphaned.
 *
 * Expiry is a session END, so it fires ExploreSessionEnded like a manual end does: the cards
 * that were served and never touched are closed as `ignored` (SCREENS.md). A session nobody
 * came back to is the plainest "seen and passed over" there is.
 */
final class ExpireStaleSessions
{
    /**
     * @return int how many sessions were expired
     */
    public function __invoke(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $cutoff = $now->subMinutes((int) config('trips.session.idle_expiry_minutes', 720));
        $expired = 0;

        // Abandoned = started before the cutoff AND no feed served since it. `served_at` is the
        // activity signal: every re-anchor and refresh writes fresh recommendation rows, so a
        // session with none past the cutoff is one nobody has pulled in the idle window.
        ExploreSession::query()
            ->where('status', ExploreSessionStatus::Active)
            ->where('started_at', '<', $cutoff)
            ->whereNotExists(function ($query) use ($cutoff): void {
                $query->select(DB::raw(1))
                    ->from('recommendations')
                    ->whereColumn('recommendations.explore_session_id', 'explore_sessions.id')
                    ->where('recommendations.served_at', '>=', $cutoff);
            })
            ->chunkById(200, function ($sessions) use ($now, &$expired): void {
                foreach ($sessions as $session) {
                    $session->forceFill([
                        'status' => ExploreSessionStatus::Expired,
                        'ended_at' => $now,
                    ])->save();

                    ExploreSessionEnded::dispatch($session->id, $session->trip_id, $session->user_id);
                    $expired++;
                }
            });

        return $expired;
    }
}
