<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Actions;

use App\Domain\Recommendations\Enums\ServeReason;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Recommendations\Services\SessionAnchor;
use App\Domain\Trips\Data\ExploreSessionData;

/**
 * "Fresh picks from here" (E46, SCREENS S1) — the user asking, in as many words,
 * for the menu to be re-cooked.
 *
 * The move re-anchor is the same machinery with the drift test in front of it. This
 * is the version with no test at all beyond "are you allowed to": the user has
 * decided the feed is stale, and they are better placed to know that than a distance
 * threshold is. They may not have moved a metre — they may simply have eaten.
 */
final class RefreshSessionFeed
{
    public function __construct(
        private readonly RankSession $rank,
        private readonly SessionAnchor $anchor,
    ) {}

    /**
     * @return bool whether a new batch was actually served — false when the session
     *              is over, has no anchor to rank from, has exhausted its serve
     *              budget, or has simply run out of things left to say
     */
    public function __invoke(ExploreSessionData $session): bool
    {
        if (! $session->isLive()) {
            return false;
        }

        /*
         * The ceiling applies to the explicit refresh too.
         *
         * It would be easy to argue the user pressing a button should always get what
         * they asked for. But every serve is a rank, a rank costs money (PRD §14.3),
         * and a button is exactly what an accidental loop leans on — a stuck finger,
         * a retrying client, a bored tester. The budget is a property of the session,
         * not of the reason we spent it.
         */
        $serves = (int) Recommendation::query()
            ->where('explore_session_id', $session->id)
            ->distinct()
            ->count('served_at');

        if ($serves >= (int) config('trips.reanchor.max_serves_per_session')) {
            return false;
        }

        $anchor = $this->anchor->current($session);

        if ($anchor === null) {
            return false;
        }

        return $this->rank->serve(
            $session->reAnchoredAt($anchor),
            ServeReason::ManualRefresh,
        ) !== [];
    }
}
