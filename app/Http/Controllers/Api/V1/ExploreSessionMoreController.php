<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Opportunities\Queries\ListOpportunitiesForSession;
use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SessionOpportunityResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** "Show more" (S1, E33) — appends the next menu's worth and returns the extended feed. */
final class ExploreSessionMoreController extends Controller
{
    public function store(ExploreSession $exploreSession, RankSession $rank, ListOpportunitiesForSession $list): AnonymousResourceCollection
    {
        $session = ExploreSessionData::fromModel($exploreSession);
        $rank->serveMore($session);

        return SessionOpportunityResource::collection($list($session));
    }
}
