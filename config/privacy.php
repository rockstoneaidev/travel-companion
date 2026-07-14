<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Privacy policy, as numbers (PRD §16)
    |--------------------------------------------------------------------------
    |
    | "Numbers, not adjectives — versioned in config/privacy.php like every other
    | constant set." A retention policy that lives in prose is a policy nobody can
    | test. This one is executable, and the tests below it prove deletion actually
    | deletes.
    |
    | Bump `version` when any number here changes: PRD §15 versions every decision
    | input, and what we retained about someone is a decision.
    |
    */

    'version' => 'v1',

    /*
    | The controller, as published (GDPR Art. 13(1)(a)).
    |
    | The privacy notice and the terms both have to name a contact, and it has to be
    | the same one in both places and in the Art. 30 record. So it lives here once,
    | rather than being typed into two React pages that can drift apart.
    */
    'controller_email' => env('PRIVACY_CONTACT_EMAIL', 'rockstoneaidev@gmail.com'),

    /*
    | Raw precise location — context events and session origins.
    |
    | Kept 30 days, then coarsened to an H3 res-8 cell plus derived signals, and
    | the precise coordinates are HARD-deleted. Not soft-deleted, not archived: the
    | point of storage limitation (GDPR Art. 5) is that the data is gone.
    */
    'raw_location_retention_days' => 30,

    /*
    | Recommendation traces (PRD §15) are kept indefinitely for replay — a trace is
    | how we answer "why did I get this suggestion?" — but their LOCATION fields are
    | coarsened on the same schedule. Keeping the decision does not require keeping
    | the coordinates.
    |
    | Except for accounts with explicit research consent, whose full-precision traces
    | feed the gold-trace suite (§15.2). That is an opt-in, and it is off by default.
    */
    'trace_location_retention_days' => 30,

    /*
    | Explicit consent for the inferred taste profile (Art. 9(2)(a), DPIA §3.2).
    |
    | We never ASK for special-category data. But the taxonomy has a `religious_sacred`
    | domain and a `spiritual` facet, and the profile learns a weight for them — so a
    | person who keeps visiting churches accumulates a vector that is, in substance, an
    | inferred statement about their religious belief. Art. 6 consent does not cover
    | that; Art. 9(2)(a) requires EXPLICIT consent.
    |
    | Versioned because the thing consented TO can change: if the profile ever infers
    | more than it does today, the old agreement does not cover it and we must ask
    | again. Bumping this invalidates every existing consent — which is the point.
    */
    'profiling_consent_version' => 'v1',

    /*
    | The declared home zone (Phase 1's whole sensitive-zone scope).
    |
    | Inside it: no learning, no opportunities served, no precise storage. Automatic
    | home/work inference is Phase 2 — it needs the background patterns Phase 1
    | deliberately does not collect.
    |
    | Relevant immediately rather than theoretically: Stockholm testing happens from
    | the founder's actual home.
    */
    'home_zone' => [
        'default_radius_meters' => 300,
        'min_radius_meters' => 100,
        'max_radius_meters' => 2_000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Home-zone inference (E40, PRD §16)
    |--------------------------------------------------------------------------
    |
    | Proposing a home zone from background patterns. Conservative on purpose: a wrongly
    | proposed zone that the user waves through would silently suppress a whole
    | neighbourhood, so the bar to even ASK is high.
    |
    */
    'home_inference' => [
        // Nights of evidence before we will propose anything. A weekend away must not
        // become "home"; a fortnight in one flat should.
        'min_nights' => 5,

        // Home must stand clear of the runner-up cell. Two bases split evenly is genuinely
        // ambiguous, and an ambiguous home zone is one we should not propose at all.
        'dominance_ratio' => 1.8,

        // Where confidence saturates.
        'confident_at_nights' => 14,
    ],

];
