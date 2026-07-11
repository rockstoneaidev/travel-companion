<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tile & coverage constants — conventions/12 (DECIDED 2026-07-11), PRD §9.2–9.3
|--------------------------------------------------------------------------
|
| Versioned like everything else: coverage shapes and Stage-A speed constants
| are product decisions, not magic numbers at call sites.
|
*/

return [

    // Canonical tile: H3 res 8 (~0.74 km², edge ≈ 460 m). Fixed — travel mode
    // changes coverage, never resolution (mode silos would fragment the cache).
    'resolution' => 8,

    // Approx res-8 cell center spacing, used to size k-rings from meters.
    'cell_spacing_m' => 875,

    // Uniqueness neighborhood (SCORING §3): k-ring 1, expanded stepwise when
    // sparse — rural tiles must not produce percentiles over 4 places.
    'uniqueness' => [
        'k' => 1,
        'min_places' => 30,
        'max_k' => 3,
    ],

    // PRD §10 Stage A: effective speeds (km/h, terrain-corrected) and the
    // outbound share of the time budget that defines session reach.
    'modes' => [
        'walk' => ['speed_kmh' => 5.85, 'outbound_fraction' => 0.22, 'corridor_width_m' => 800],
        'bike' => ['speed_kmh' => 18.2, 'outbound_fraction' => 0.20, 'corridor_width_m' => 1500],
        'drive' => ['speed_kmh' => 54.0, 'outbound_fraction' => 0.25, 'corridor_width_m' => 3000],
    ],

    'coverage' => [
        'max_k' => 48,                 // disc safety cap; drive should be cone/corridor anyway
        'cone_half_angle_deg' => 60,   // full reach within ±60° ahead of heading
        'behind_fraction' => 0.40,     // ~40% reach behind (the "pear")
        'near_ring_m' => 1200,         // walking-scale ring around origin/destination — ScoutRange::Near
    ],

];
