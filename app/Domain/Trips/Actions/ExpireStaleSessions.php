<?php

declare(strict_types=1);

namespace App\Domain\Trips\Actions;

use App\Domain\Trips\Enums\ExploreSessionStatus;
use App\Domain\Trips\Events\ExploreSessionEnded;
use App\Domain\Trips\Models\ExploreSession;
use Carbon\CarbonImmutable;

/**
 * The session reaper (conventions/03; ExploreSessionStatus::Expired). A session whose time
 * budget elapsed and which nobody ended is Expired — it reads `expires_at` and nothing else,
 * exactly as the status enum promises.
 *
 * Left un-reaped, sessions stay `active` for ever: a three-hour session opened last night is
 * still "live" the next afternoon. That is not harmless — it is why the feed keeps a session
 * on a clock that ran out, and why "Show me everything around me" reckoned against a budget
 * that had gone deeply negative. A session that is over should say it is over.
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
        $expired = 0;

        ExploreSession::query()
            ->where('status', ExploreSessionStatus::Active)
            ->where('expires_at', '<', $now)
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
