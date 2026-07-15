<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Actions;

use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Trips\Data\ExploreSessionData;

/**
 * "Show more" (S1) — the published verb behind the feed's next-best button.
 *
 * A thin front for {@see RankSession::serveMore()}, so both the Inertia and the API
 * controllers stay thin wrappers over one domain service (the API-first boundary).
 */
final class ExtendSessionFeed
{
    public function __construct(
        private readonly RankSession $rank,
    ) {}

    public function __invoke(ExploreSessionData $session): void
    {
        $this->rank->serveMore($session);
    }
}
