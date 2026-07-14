<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Opportunities\Queries\ListOpportunitiesForSession;
use App\Domain\Recommendations\Queries\CurrentServe;
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
 * A GET here is allowed to WRITE, which is worth saying out loud: the feed
 * re-anchors when the user has moved and tops itself up when they have dismissed
 * their way below a full menu (E46), so this endpoint may rank and persist a new
 * batch before it answers. It is idempotent in the sense that matters — pulling
 * twice from the same spot within the re-serve interval returns the same batch — but
 * it is not read-only, and a caching layer must not treat it as though it were.
 */
final class ExploreSessionOpportunityController extends Controller
{
    public function index(
        ExploreSession $exploreSession,
        ListOpportunitiesForSession $listOpportunities,
        CurrentServe $currentServe,
    ): AnonymousResourceCollection {
        $opportunities = $listOpportunities(ExploreSessionData::fromModel($exploreSession));

        $serve = $currentServe->for($exploreSession->id);

        return SessionOpportunityResource::collection($opportunities)->additional([
            'meta' => [
                'explore_session_id' => $exploreSession->id,
                // Which batch this is, why it was served, and where it was ranked from.
                // The client needs the group number to know the menu changed under it.
                'serve' => $serve?->toArray(),
            ],
        ]);
    }
}
