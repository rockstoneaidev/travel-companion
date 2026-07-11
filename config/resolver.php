<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Entity resolution constants — ENTITY-RESOLUTION.md (resolver_version v1)
|--------------------------------------------------------------------------
|
| Deterministic and versioned: same inputs + same resolver_version → same
| output. ANY change to a value here mints a new version (§1 principle 2);
| thresholds and weights are refit against the gold set (§6), never edited
| in place.
|
*/

return [

    'version' => 'v1',

    // Stage 3 — match score weights. embed_cos is defined in the spec but has
    // no embeddings until PRD §10 step 5 lands; when a signal is absent it is
    // dropped and the rest renormalized (SCORING §2.5 discipline).
    'weights' => [
        'name_sim' => 0.45,
        'proximity' => 0.25,
        'type_compat' => 0.15,
        'embed_cos' => 0.15,
    ],

    // Stage 4 — threshold bands. Asymmetric on purpose: false merges are
    // worse than duplicates.
    'bands' => [
        'auto_merge' => 0.82,
        'review' => 0.60,
    ],

    // Stage 3 — proximity ramp radius (meters) by PlaceTypeDomain.
    'proximity_radius' => [
        'default' => 100,          // dense urban POI
        'building_scale' => 150,   // religious_sacred, historic_heritage, museum_gallery, built_environment
        'nature_scale' => 500,     // nature_landscape, active_recreation
    ],

    // Stage 3 — type compatibility scores.
    'type_compat' => [
        'same_type' => 1.0,
        'same_domain' => 0.6,
        'compatible_pair' => 0.3,
    ],

    // Known-compatible cross-domain pairs (order-insensitive).
    'compatible_pairs' => [
        ['church', 'chapel'],
        ['church', 'cathedral'],
        ['cafe', 'bakery'],
        ['restaurant', 'cafe'],
        ['castle', 'fortress'],
        ['monument', 'memorial'],
        ['park', 'garden'],
    ],

    // Stage 4 — chain guard: identical names > 250 m apart in chain-prone
    // types never auto-merge on name alone (franchises are distinct places).
    'chain_guard' => [
        'types' => ['cafe', 'restaurant', 'bakery', 'specialty_shop'],
        'min_distance_m' => 250,
    ],

    // Stage 1 — explicit-ID sanity guard: joined points > 1 km apart go to
    // review (tag vandalism / stale refs exist).
    'explicit_max_distance_m' => 1000,

    // Stage 2 — blocking.
    'blocking' => [
        'h3_resolution' => 9,      // ~350 m neighborhoods via k-ring 1
        'trigram_floor' => 0.3,
    ],

    // Stage 5 — survivorship.
    'survivorship' => [
        'geometry_conflict_m' => 150,
    ],

];
