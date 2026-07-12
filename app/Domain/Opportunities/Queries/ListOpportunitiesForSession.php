<?php

declare(strict_types=1);

namespace App\Domain\Opportunities\Queries;

use App\Domain\Context\Contracts\Routing;
use App\Domain\Opportunities\Data\SessionOpportunityData;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Opportunities\Services\UrgentSlot;
use App\Domain\Places\Contracts\PlaceImageLookup;
use App\Domain\Places\Data\Coordinates;
use App\Domain\Places\Data\PlaceData;
use App\Domain\Places\Enums\PlaceType;
use App\Domain\Places\Enums\PlaceTypeDomain;
use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Enums\AppealFacet;

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
    public function __construct(
        private readonly RankSession $rank,
        private readonly Routing $routing,
        private readonly PlaceImageLookup $images,
    ) {}

    /** @return list<SessionOpportunityData> */
    public function __invoke(ExploreSessionData $session): array
    {
        if ($session->origin === null) {
            return [];   // location history erased (PRD §16) — nothing to scout from
        }

        // E7 landed: rank (or replay) the session feed, then dress the stored
        // candidate snapshots as the API shape. Server order is the order.
        $recommendations = $this->rank->feedFor($session);

        if ($recommendations === []) {
            return [];
        }

        $opportunities = Opportunity::query()
            ->whereIn('id', array_map(static fn ($r) => $r->opportunity_id, $recommendations))
            ->get()
            ->keyBy('id');

        /*
         * One query for the whole feed's photos, not one per card — a card is not worth
         * an N+1. The photos phase has been quietly filling this table for weeks while
         * every screen except the detail page ignored it: 1,516 images, zero of them on
         * the screen people actually look at.
         *
         * Through Places' contract, never its models (conventions/01).
         */
        $images = $this->images->forPlaces(
            $opportunities->pluck('place_id')->filter()->unique()->values()->all(),
        );

        $out = [];
        foreach ($recommendations as $recommendation) {
            $opportunity = $opportunities->get($recommendation->opportunity_id);
            $candidate = $recommendation->score_inputs['candidate'] ?? null;
            if ($opportunity === null || $candidate === null) {
                continue;
            }

            $place = new PlaceData(
                id: $candidate['place_id'],
                name: $candidate['name'],
                coordinates: new Coordinates($candidate['lat'], $candidate['lng']),
                type: $candidate['type'] !== null ? PlaceType::from($candidate['type']) : null,
                typeDomain: $candidate['type_domain'] !== null ? PlaceTypeDomain::from($candidate['type_domain']) : null,
                facets: array_map(static fn (string $f) => AppealFacet::from($f), $candidate['facets']),
                source: $candidate['scouts'][0] ?? 'scout',
                distanceMeters: isset($recommendation->score_inputs['reachability']['travel_min'])
                    ? (int) round((float) $recommendation->score_inputs['reachability']['travel_min'] * 60)
                    : null,
            );

            /*
             * Stage B (PRD §10): the number the user SEES is real, not estimated.
             *
             * The estimator (±20–30%) gates hundreds of candidates for free, and its
             * error is fine there — the reach ceiling already includes dwell and the
             * menu is alternatives, not a schedule. But "12 min walk" printed on a
             * card is a promise someone is about to act on, so the served handful get
             * a real route. Bounded by the feed size: ~5 calls per session, cached
             * per (place, origin res-9 tile, mode).
             *
             * EDGE-ONLY, and this is why it happens HERE and not in RankSession: a
             * Google route duration may never be written to a row (conventions/09).
             * The persisted trace keeps the estimator's number — ours — and this is
             * an overlay on the way out.
             */
            $walkMinutes = $this->routing->minutes(
                $session->origin->lat,
                $session->origin->lng,
                $place->coordinates->lat,
                $place->coordinates->lng,
                $session->travelMode,
            ) ?? (isset($recommendation->score_inputs['reachability']['travel_min'])
                ? (float) $recommendation->score_inputs['reachability']['travel_min']
                : null);

            $out[] = new SessionOpportunityData(
                id: $opportunity->id,
                kind: $opportunity->kind,
                status: $opportunity->status,
                title: $opportunity->title ?? $candidate['name'],
                summary: $opportunity->summary,
                place: $place,
                distanceMeters: $place->distanceMeters,
                windowStartsAt: $opportunity->window_starts_at,
                windowEndsAt: $opportunity->window_ends_at,
                expiresAt: $opportunity->expires_at,
                recommendationId: $recommendation->id,
                walkMinutes: $walkMinutes,
                image: $images[$opportunity->place_id] ?? null,
            );
        }

        // Server order is the order — except for the one exception the spec
        // allows: the GO NOW slot is promoted to the top (SCREENS S1).
        return UrgentSlot::fromConfig()->apply($out);
    }
}
