<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Opportunities\Queries\ListOpportunitiesForSession;
use App\Domain\Recommendations\Queries\PendingVisitPrompts;
use App\Domain\Trips\Actions\StartExploreSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Enums\TravelMode;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Queries\FindActiveExploreSessionForUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ExploreSessions\StoreExploreSessionRequest;
use App\Http\Resources\Api\V1\ExploreSessionResource;
use App\Http\Resources\Api\V1\SessionOpportunityResource;
use App\Http\Resources\Api\V1\VisitPromptResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Inertia twin of Api\V1\ExploreSessionController. Same Form Request, same
 * actions, same Resources — Inertia is a delivery surface, not a second backend
 * (CLAUDE.md, conventions/04).
 */
final class ExploreSessionController extends Controller
{
    /**
     * One entry point (SCREENS S1/S2): while a session is open, "explore" *is* the
     * feed — the start form only exists when there's nothing to go back to.
     */
    public function index(Request $request, FindActiveExploreSessionForUser $findActiveSession): Response|RedirectResponse
    {
        $session = $findActiveSession((int) $request->user()->id);

        if ($session !== null) {
            return to_route('explore.show', $session);
        }

        return Inertia::render('explore/index', [
            'travelModeOptions' => TravelMode::options(),
        ]);
    }

    public function store(StoreExploreSessionRequest $request, StartExploreSession $startExploreSession): RedirectResponse
    {
        $session = $startExploreSession($request->toData());

        return to_route('explore.show', $session);
    }

    public function show(
        ExploreSession $exploreSession,
        ListOpportunitiesForSession $listOpportunities,
        PendingVisitPrompts $pendingVisitPrompts,
    ): Response {
        $opportunities = $listOpportunities(ExploreSessionData::fromModel($exploreSession));

        return Inertia::render('explore/show', [
            'session' => new ExploreSessionResource($exploreSession->load('trip')),
            'opportunities' => SessionOpportunityResource::collection($opportunities),
            // "Were you there?" — the time half of the rule is settled here; the
            // client applies the proximity half (SCREENS S4).
            'visitPrompts' => VisitPromptResource::collection(
                $pendingVisitPrompts->forUser((int) $exploreSession->user_id),
            ),
        ]);
    }
}
