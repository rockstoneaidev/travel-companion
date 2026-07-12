<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Entity resolution constants — ENTITY-RESOLUTION.md
|--------------------------------------------------------------------------
|
| Deterministic and versioned: same inputs + same resolver_version → same
| output. ANY change to a value here mints a new version (§1 principle 2);
| thresholds and weights are refit against the gold set (§6), never edited
| in place.
|
| Every version is KEPT, not replaced. `resolver_version` is stamped on every
| decision and every merge, so a stored decision must stay explicable by the
| constants that produced it — and `resolver:gold-report --version=v1` has to
| still reproduce v1's numbers later.
|
| The active version is flattened to the top level, so callers keep reading
| config('resolver.bands.auto_merge') and get the active model.
|
*/

$versions = [

    /*
    | v1 — the original hand-chosen constants (2026-07-12). Never measured
    | against labeled data; kept verbatim so old decisions stay reproducible.
    */
    'v1' => [
        // Stage 3 — match score weights. embed_cos is defined in the spec but
        // has no embeddings yet; an absent signal is dropped and the rest
        // renormalized (SCORING §2.5 discipline).
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
    ],

];

/*
| v2 — refit against the Stockholm gold set (2026-07-14).
|
| v1 measured precision 1.0000 / recall 0.8326: no false merges, but 39 real
| duplicates left unmerged. Those misses were not marginal name matches — they
| were *identical* names. "Moderna museet" ↔ "Moderna Museet" scored 0.7226;
| "Stockholms slott" ↔ "Stockholms slott" scored 0.7743. All large buildings.
|
| The cause is the proximity ramp, not the name signal. Sources place a big
| building differently — a centroid, an entrance, an address node — and they are
| routinely 100–150 m apart while describing the same palace. At a 150 m
| building_scale radius that drives proximity to ~0.06, and 0.294 × 0.06 cannot
| carry an otherwise perfect match over 0.82.
|
| So v2 widens the ramp for building-scale domains to 250 m — but ONLY where the
| names are already essentially identical (name_sim ≥ 0.95).
|
| That condition is not decoration; without it the change is a false-merge bug.
| An unconditional widening lifts "Galleri Duerr" ↔ "Galleri Dover" — two
| different galleries 80 m apart, name_sim 0.88 — from 0.78 (review) to 0.84
| (auto-merge). The gold set did not catch that, because its auto-labeled
| negatives are all easy ones; a hand-written unit test did.
|
| The discriminator is exactness. A coordinate gap means different things
| depending on the name: two sources saying "Stockholms slott" 115 m apart are
| describing one building from a centroid and an entrance, while two sources
| saying "Duerr" and "Dover" 80 m apart are describing two galleries. Only the
| first reading licenses a wider ramp.
|
| Anchoring to an exact name is also what keeps generic municipal names safe —
| several "Lekparken", several "Apoteket". Those are playgrounds and pharmacies:
| they are not building-scale domains, so they never see the wider radius at all,
| and an unqualified "identical name ⇒ merge" rule (which v2 does NOT add) would
| have merged them.
|
| Measured on the 633-pair Stockholm gold set: precision stays 1.0000 (zero
| false merges), recall 0.8326 → 0.8498 (35 residual misses, all routed to review).
|
| WHAT v2 DELIBERATELY DOES NOT DO — and why, because the numbers are seductive.
|
| Dropping auto_merge from 0.82 to 0.75 scores precision 1.0000 and recall
| 0.9614 on this same set. That result is worthless. The gold set's negatives are
| auto-labeled as "different domain AND > 100 m apart", and the hardest of them
| scores 0.6656 — not one negative reaches 0.70. So ANY threshold at or above
| 0.70 earns precision 1.0 by construction, having never been shown a pair that
| could plausibly false-merge.
|
| The pairs that could are the 60 sitting in stockholm-test.todo.json awaiting a
| human, 14 of which already score above 0.82. Until those are labeled, this set
| can prove the absence of false merges only among pairs incapable of producing
| one. Threshold changes wait for that; a mechanism fix does not.
|
| The 33 remaining misses are not lost, either: they land in the REVIEW band and
| are queued to /admin/entity-resolution. Most are pairs where the two sources
| disagree about the *type* ("Moderna museet" is a museum to one source and a
| gallery to the other, costing type_compat 1.0 → 0.6). The resolver refusing to
| auto-merge when sources cannot agree what a thing IS is correct behaviour, not
| a bug — a duplicate is annoying, a false merge is corruption.
*/
$versions['v2'] = array_replace_recursive($versions['v1'], [
    'proximity_radius' => [
        // Applies ONLY when the names are already essentially identical, and
        // only to building-scale domains. See the note above: an unconditional
        // widening merges "Galleri Duerr" into "Galleri Dover".
        'exact_name_building_scale' => [
            'min_name_sim' => 0.95,
            'radius_m' => 250,
        ],
    ],
]);

$active = 'v2';

return [
    ...$versions[$active],
    'version' => $active,
    'versions' => $versions,
];
