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
        // The NOW feed: how many full cards a pull returns. PRD §6.6 said "3–5"; the
        // founder wanted more room to choose, so 10. This is the size of ONE menu — "Show
        // more" serves another menu's worth, appended, and "Show me everything" (E51) drops
        // the menu framing entirely.
        //
        // NOTE: this is NOT the notification budget. The push cap (3/day, never a pull) is
        // `notifications.budget.max_per_day` — a completely different number for a completely
        // different act. Interrupting somebody is not the same as answering when they ask.
        'feed_size' => 10,

        /*
         * ...and the number of places you may LOOK AT, which is a third question again (E51).
         *
         * The feed is CARDS — each with a materialised opportunity, a voice line, an image.
         * These are the browse list, which is scored candidates and nothing more: no
         * opportunity row, no LLM, no Google call. That is why it can be large where the feed
         * is not — the pipeline had already scored all of them and was throwing them away.
         */
        'browse_page_size' => 50,
        'browse_max' => 200,

        'min_time_budget_minutes' => 15,
        'max_time_budget_minutes' => 720,

        // How long a live session may go with NO activity before the reaper calls it
        // abandoned and marks it `expired`. This is NOT the time budget: the budget is a
        // reach envelope that never counts down (a "3-hour" session can be explored for
        // eight). A session ends when the traveller ends it, when a new one supersedes it,
        // or when it has plainly been walked away from — and 12 hours of silence (an
        // overnight gap) is the honest signal for the last. Activity = the last feed serve.
        'idle_expiry_minutes' => 720,

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

        // ...but the emulator compresses time. A 60× walk crosses Stockholm in a minute,
        // and a two-minute hold would let the pipeline react once and then watch the rest
        // of the journey go by. The interval is a courtesy to a human reading a screen
        // (ADMIN §6); in a simulation there is nobody reading. The drift threshold above
        // is NOT relaxed — "did they actually move" is a question about the world.
        'min_interval_seconds_emulated' => 8,

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

    /*
    |--------------------------------------------------------------------------
    | Trip Mode — the background stream (E29; PRD §13.4)
    |--------------------------------------------------------------------------
    |
    | "The phone sends MEANINGFUL CONTEXT CHANGES — never a raw GPS stream." That is a
    | promise about battery and about trust, and promises that live only in a mobile
    | client last until the next release. So the floor is enforced on the server, and
    | these are the numbers it enforces.
    |
    | Generous on purpose: they are not trying to second-guess a good summarizer, only to
    | make a bad one harmless. A phone behaving as §13.4 describes never notices them.
    |
    */
    'trip_mode' => [
        // A different place, whatever the clock says.
        'min_distance_meters' => 250,

        // A different moment, even standing still — the light changed, the market opened.
        // EITHER of these makes an event meaningful; requiring both would discard the
        // traveller who sat in a café for an hour and then walked out, which is precisely
        // the moment the companion exists for.
        'min_interval_seconds' => 600,
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

    /*
    |--------------------------------------------------------------------------
    | Trip segments — tempo inference (E38, PRD §6.6)
    |--------------------------------------------------------------------------
    |
    | Three numbers decide what kind of day it was. These are the thresholds, and they
    | are product opinions, not physics — which is exactly why they live here and why
    | InferTripSegments stamps a version on every row it writes.
    |
    */
    'segments' => [
        // You ended the day 40 km from where you woke up. Wherever you were going, you
        // went there: that is a travel day, and also the trip's route-leg.
        'travel_min_net_displacement_m' => 40_000,

        // You came back to where you started, but you covered ground doing it. Either
        // measure is enough on its own — a long thin day along a coast road and a dense
        // day criss-crossing an old town are both sightseeing, and only one of them has
        // a big span.
        'sightseeing_min_span_m' => 2_500,
        'sightseeing_min_cells' => 6,      // res-8 cells ≈ 0.75 km² each: six means you were IN places

        // Trip Mode's floor fires at 250 m or 10 minutes, so a watched day produces dozens
        // of events. Twelve is where we stop hedging about what we saw.
        'confident_at_events' => 12,
    ],

];
