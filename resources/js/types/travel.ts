// Mirrors of the API Resources in app/Http/Resources/Api/V1 (docs/conventions/06).
// The Inertia page props and the /api/v1 payloads are the same shape by design —
// one contract, not two.

import {
    type AppealFacet,
    type ExploreSessionStatus,
    type OpportunityKind,
    type PlaceType,
    type PlaceTypeDomain,
    type TravelMode,
    type TripSource,
    type TripStatus,
} from './enums';

export interface Coordinates {
    lat: number;
    lng: number;
}

/** app/Http/Resources/Api/V1/TripResource.php */
export interface Trip {
    id: string;
    name: string | null;
    status: TripStatus;
    source: TripSource;
    started_at: string | null;
    last_session_at: string | null;
    ended_at: string | null;
    created_at: string;
    explore_sessions_count?: number; // whenCounted()
    explore_sessions?: ExploreSession[]; // whenLoaded()
}

/** app/Http/Resources/Api/V1/ExploreSessionResource.php */
export interface ExploreSession {
    id: string;
    trip_id: string;
    status: ExploreSessionStatus;
    origin: Coordinates | null;
    destination_point: Coordinates | null;
    time_budget_minutes: number;
    travel_mode: TravelMode;
    heading: number | null;
    reach_meters: number;
    started_at: string;
    expires_at: string;
    ended_at: string | null;
    trip?: Trip; // whenLoaded()
}

/** app/Http/Resources/Api/V1/PlaceResource.php — geo-core only, ships its attribution. */
export interface Place {
    id: string;
    name: string;
    location: Coordinates;
    type: PlaceType;
    type_domain: PlaceTypeDomain;
    facets: AppealFacet[];
    source: string;
}

/**
 * app/Http/Resources/Api/V1/SessionOpportunityResource.php
 *
 * No `scores` — the feed is not ranked yet. E7 (SCORING.md) adds the sub-scores,
 * the composite and `scoring_model_version`.
 */
export interface SessionOpportunity {
    id: string;
    kind: OpportunityKind;
    status: string;
    title: string | null;
    summary: string | null;
    distance_meters: number | null;
    time_window: {
        starts_at: string | null;
        ends_at: string | null;
    };
    /** The GO NOW slot — the server's call, at most one per feed (SCREENS S1). */
    urgent: boolean;
    expires_at: string;
    recommendation_id: string | null;
    walk_minutes: number | null;
    /** The photo, with its attribution. Null renders the designed paper-stripe fallback. */
    image: { url: string; attribution: string | null; license: string | null } | null;
    place: Place;
}

/**
 * A pending "Were you there?" question (SCREENS S4).
 * Mirrors app/Http/Resources/Api/V1/VisitPromptResource.php.
 *
 * The server has already applied the time half of the rule; the client applies
 * the proximity half, which is why the place's coordinates travel with it.
 */
export interface VisitPrompt {
    recommendation_id: string;
    place_name: string;
    location: { lat: number; lng: number };
}

/**
 * One kept item (SCREENS S6).
 * Mirrors app/Http/Resources/Api/V1/KeptItemResource.php.
 *
 * `still_possible` is the server's live re-check against the world model, not a
 * property of the keep — a thing kept while its window was open can be passed by
 * the time you look at the list.
 */
export interface KeptItem {
    recommendation_id: string;
    image: { url: string; attribution: string | null; license: string | null } | null;
    opportunity_id: string | null;
    title: string;
    note: string | null;
    location: { lat: number; lng: number };
    kept_at: string;
    window_ends_at: string | null;
    still_possible: boolean;
}
