<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Implicit trip clustering (PRD §6.6)
    |--------------------------------------------------------------------------
    |
    | A new explore session attaches to the user's live trip when it is close in
    | space and time; otherwise it opens a new one. Trip attribution is a
    | *derived* clustering, so these thresholds are low-stakes and recomputable —
    | but the version they were computed under is stored on every trip
    | (`clustering_version`), per PRD §15.1 "version everything".
    |
    | The region test is a PostGIS distance against the trip's anchor point (the
    | origin of its first session). H3 is the cache/coverage unit (conventions/12),
    | not the clustering unit — a region is bigger than a res-8 hex, and there is
    | no H3 binding in the PHP runtime yet (see E5).
    |
    */

    'clustering' => [
        'version' => env('TRIP_CLUSTERING_VERSION', 'v1'),

        // Same region: within this radius of the trip's anchor point.
        'region_radius_meters' => 150_000,

        // Same trip: at most this long since the trip's last session.
        'max_gap_days' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Explore sessions
    |--------------------------------------------------------------------------
    */

    'session' => [
        // PRD §6.6 — "a 3–5 item feed".
        'feed_size' => 5,

        'min_time_budget_minutes' => 15,
        'max_time_budget_minutes' => 720,

        // Hard cap on the reach radius derived from budget × mode speed, so a
        // 12-hour drive session cannot ask PostGIS for half a continent.
        'max_reach_meters' => 120_000,
    ],

];
