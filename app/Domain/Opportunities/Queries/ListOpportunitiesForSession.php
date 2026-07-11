<?php

declare(strict_types=1);

namespace App\Domain\Opportunities\Queries;

use App\Domain\Opportunities\Data\SessionOpportunityData;
use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Places\Contracts\PlaceLookup;
use App\Domain\Places\Data\PlaceData;
use App\Domain\Trips\Data\ExploreSessionData;

/**
 * ===========================================================================
 *  THE E5 / E7 SEAM — read this before changing it.
 * ===========================================================================
 *
 * `GET /api/v1/explore-sessions/{session}/opportunities` has to return
 * *something* today, and the honest something is: whatever the world model can
 * actually supply, in a shape the real pipeline will keep.
 *
 * What this does now:
 *   1. asks Places for everything within the session's reach radius
 *      (ExploreSessionData::reachMeters() — the Stage-A estimator, PRD §10);
 *   2. returns the live, unexpired opportunities attached to those places,
 *      **ordered by distance**, capped at the feed size (config/trips.php).
 *
 * What it deliberately does NOT do:
 *   - **It does not rank.** Distance order is not a ranking and must not be
 *     mistaken for one. `personal_fit`, `uniqueness`, `temporal_urgency`,
 *     `route_fit`, `novelty` and the composite are **E7** (SCORING.md). A fake
 *     ranking here would be worse than none: it would look finished.
 *   - **It does not scout.** Nothing fills `opportunities` yet — the scouts,
 *     the shared H3 tile cache and the enrichment pipeline are **E5** (PRD §9.3,
 *     conventions/12). Until E5 lands, this returns an empty, well-formed
 *     collection for a real user in a real city. That is correct, not broken.
 *   - **It does not write a `recommendation`.** What was served, with its trace,
 *     is E7's job (PRD §15.1) — which is why the recommendations table now
 *     carries `explore_session_id`/`trip_id` (migration 2026_07_13_000004) and
 *     nothing here populates them.
 *
 * When E5/E7 land, the signature stays: session in, feed out. The body becomes
 * tile-cache lookup → score → select.
 */
final class ListOpportunitiesForSession
{
    public function __construct(private readonly PlaceLookup $places) {}

    /** @return list<SessionOpportunityData> */
    public function __invoke(ExploreSessionData $session): array
    {
        if ($session->origin === null) {
            return [];   // location history erased (PRD §16) — nothing to scout from
        }

        $feedSize = (int) config('trips.session.feed_size');

        $places = $this->places->withinRadius($session->origin, $session->reachMeters());

        if ($places === []) {
            return [];
        }

        /** @var array<string, PlaceData> $byPlaceId */
        $byPlaceId = [];
        $order = [];

        foreach ($places as $index => $place) {
            $byPlaceId[$place->id] = $place;
            $order[$place->id] = $index;      // Places returned them nearest-first
        }

        $opportunities = Opportunity::query()
            ->whereIn('place_id', array_keys($byPlaceId))
            ->whereNotIn('status', array_map(
                fn (OpportunityStatus $status): string => $status->value,
                OpportunityStatus::terminal(),
            ))
            ->where('expires_at', '>', now())
            ->get()
            ->sortBy(fn (Opportunity $opportunity): int => $order[$opportunity->place_id])
            ->take($feedSize)
            ->values();

        return $opportunities
            ->map(fn (Opportunity $opportunity): SessionOpportunityData => new SessionOpportunityData(
                id: $opportunity->id,
                kind: $opportunity->kind,
                status: $opportunity->status,
                title: $opportunity->title ?? $byPlaceId[$opportunity->place_id]->name,
                summary: $opportunity->summary,
                place: $byPlaceId[$opportunity->place_id],
                distanceMeters: $byPlaceId[$opportunity->place_id]->distanceMeters,
                windowStartsAt: $opportunity->window_starts_at,
                windowEndsAt: $opportunity->window_ends_at,
                expiresAt: $opportunity->expires_at,
            ))
            ->all();
    }
}
