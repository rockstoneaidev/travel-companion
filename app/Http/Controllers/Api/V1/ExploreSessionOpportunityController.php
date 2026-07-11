<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Opportunities\Queries\ListOpportunitiesForSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SessionOpportunityResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `GET /api/v1/explore-sessions/{session}/opportunities` — the session's feed.
 *
 * Bounded by construction (3–5 items, PRD §6.6), so it does not paginate
 * (conventions/07's stated exemption).
 *
 * The feed is not ranked yet: see ListOpportunitiesForSession for exactly what
 * this returns today and what E5/E7 change.
 */
final class ExploreSessionOpportunityController extends Controller
{
    public function index(
        ExploreSession $exploreSession,
        ListOpportunitiesForSession $listOpportunities,
    ): AnonymousResourceCollection {
        $opportunities = $listOpportunities(ExploreSessionData::fromModel($exploreSession));

        return SessionOpportunityResource::collection($opportunities)->additional([
            'meta' => [
                'explore_session_id' => $exploreSession->id,
                'ordering' => 'distance',            // NOT a ranking — E7 replaces this with the scoring model
                'scoring_model_version' => null,     // populated by E7 (PRD §15.1)
            ],
        ]);
    }
}
