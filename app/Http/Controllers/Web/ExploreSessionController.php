<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Opportunities\Queries\ListOpportunitiesForSession;
use App\Domain\Places\Queries\CountPlacesAround;
use App\Domain\Recommendations\Queries\CurrentServe;
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

    /**
     * S3 — the same feed, drawn as geography. Same session, same domain query, same
     * server-decided urgency: the map is a second *view* of the feed, never a second
     * ranking. Nothing here re-sorts or re-filters what the feed already decided.
     */
    public function map(ExploreSession $exploreSession, ListOpportunitiesForSession $listOpportunities): Response
    {
        $opportunities = $listOpportunities(ExploreSessionData::fromModel($exploreSession));

        return Inertia::render('explore/map', [
            'session' => new ExploreSessionResource($exploreSession->load('trip')),
            'opportunities' => SessionOpportunityResource::collection($opportunities),
        ]);
    }

    /**
     * `/map` with no session is a bookmark, not a screen: send it to the map of the
     * session they actually have — or to the start form if they have none, exactly as
     * `/explore` does. One entry point, one rule.
     */
    public function activeMap(Request $request, FindActiveExploreSessionForUser $findActiveSession): RedirectResponse
    {
        $session = $findActiveSession((int) $request->user()->id);

        return $session !== null
            ? to_route('explore.map', $session)
            : to_route('explore.index');
    }

    public function show(
        ExploreSession $exploreSession,
        ListOpportunitiesForSession $listOpportunities,
        PendingVisitPrompts $pendingVisitPrompts,
        CurrentServe $currentServe,
        CountPlacesAround $placesAround,
    ): Response {
        $session = ExploreSessionData::fromModel($exploreSession);
        $opportunities = $listOpportunities($session);

        return Inertia::render('explore/show', [
            /*
             * Do we know this area AT ALL? (PRD §8.1, §15.3.)
             *
             * Only asked when the feed is empty, and only because an empty feed has two
             * completely different meanings that we were rendering identically: "we swept
             * this neighbourhood and nothing is worth your time" and "we have never heard
             * of this town". The world model is region-scoped (Stockholm + the France
             * corridor), so outside it the honest answer is that we do not know the place —
             * not that we are watching it and it is quiet.
             *
             * One indexed count against our own table. No scouts, no APIs, no cost.
             */
            'coverage' => [
                'known' => $opportunities !== [] || ($session->origin !== null && $placesAround->within(
                    $session->origin->lat,
                    $session->origin->lng,
                    $session->reachMeters(),
                ) > 0),
            ],
            'session' => new ExploreSessionResource($exploreSession->load('trip')),
            'opportunities' => SessionOpportunityResource::collection($opportunities),
            // Which batch this is (E46). Read AFTER the feed, because the feed is what
            // decides whether this pull re-anchored; asking first would describe the
            // menu the user is being moved away from.
            'serve' => $currentServe->for($exploreSession->id)?->toArray(),
            // "Were you there?" — the time half of the rule is settled here; the
            // client applies the proximity half (SCREENS S4).
            'visitPrompts' => VisitPromptResource::collection(
                $pendingVisitPrompts->forUser((int) $exploreSession->user_id),
            ),
        ]);
    }
}
