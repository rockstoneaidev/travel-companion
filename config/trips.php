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

    /*
    |--------------------------------------------------------------------------
    | The living feed (E46, PRD §8.1 "re-opening yields a fresh menu", §9.2)
    |--------------------------------------------------------------------------
    |
    | A session's feed is re-served when the user has actually MOVED, when they
    | ask for fresh picks, or when dismissals have thinned the menu. These are the
    | numbers that decide "actually moved" — in config, not at the call site,
    | because they are a product decision about how twitchy the feed feels.
    |
    */

    'reanchor' => [
        // How far from the batch's anchor before the feed is ranked from somewhere
        // else. A res-8 cell is ~875 m across, so 400 m is "you are meaningfully
        // elsewhere in this neighbourhood" — Liljeholmen → Hornstull is ~1.5 km and
        // fires comfortably. Deliberately a DISTANCE and not "the H3 cell changed":
        // a cell test flaps for anyone standing on a tile boundary, re-serving the
        // feed every time they cross the street.
        'min_drift_meters' => 400,

        // Never two automatic re-serves closer together than this. A re-serve is a
        // rank (cheap: tiles are cached) but it also churns the user's menu, and a
        // menu that reshuffles while you read it is worse than a stale one.
        'min_interval_seconds' => 120,

        // Safety net on cost and churn: a pathological client posting positions in a
        // loop must not be able to rank a session an unbounded number of times.
        // Explicit refreshes and backfills count too — this is a ceiling on serves,
        // not on movement.
        'max_serves_per_session' => 20,

        // How stale a context event may be and still be treated as "where the user
        // is now". Beyond this we fall back to the last anchor rather than
        // re-ranking from a position that may be an hour old.
        'position_max_age_seconds' => 900,
    ],

    'feed' => [
        // An opportunity wins the GO NOW slot only if its window closes within
        // this horizon (SCREENS S1). Wider, and "go now" stops meaning now.
        'urgent_horizon_minutes' => 120,
    ],

    /*
    | "Were you there?" (SCREENS S4) — the single most valuable tap in the
    | learning loop. Asked only after a "Take me", once enough time has passed
    | that the answer is meaningful, and only near the place. §18.5 tunables.
    */
    'visit_prompt' => [
        'min_minutes_since_take_me' => 20,
        'within_meters' => 150,
    ],

];
