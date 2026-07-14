<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Opportunities\Queries\ListOpportunitiesForSession;
use App\Domain\Recommendations\Actions\RefreshSessionFeed;
use App\Domain\Recommendations\Queries\CurrentServe;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SessionOpportunityResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `POST /api/v1/explore-sessions/{session}/refresh` — "fresh picks from here" (E46).
 *
 * Additive, per the API-first boundary (CLAUDE.md): the Phase-2 mobile client gets
 * the living feed from the same domain action the web client uses, not from a
 * re-implementation.
 */
final class ExploreSessionRefreshController extends Controller
{
    public function store(
        Request $request,
        ExploreSession $exploreSession,
        RefreshSessionFeed $refresh,
        ListOpportunitiesForSession $listOpportunities,
        CurrentServe $currentServe,
    ): AnonymousResourceCollection {
        $session = ExploreSessionData::fromModel($exploreSession);

        $refresh($session);

        return SessionOpportunityResource::collection($listOpportunities($session))
            ->additional(['meta' => [
                'serve' => $currentServe->for($exploreSession->id)?->toArray(),
            ]]);
    }
}
