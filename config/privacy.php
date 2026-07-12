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

];
