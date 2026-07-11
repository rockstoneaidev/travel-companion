<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Scoring constants — SCORING.md (scoring_model_version v1, growing with E7)
|--------------------------------------------------------------------------
|
| E6 lands the Stage-A travel-time estimator (PRD §10). Everything here is
| versioned: refitting against pilot data mints v2, never edits v1.
|
*/

return [

    // The ONLY thing config decides (SCORING §9.1): which immutable constant
    // set is live. The sets themselves are code (ScoringModel::v1()).
    'active_version' => 'v1',

    'version' => 'v1',

    // PRD §10 Stage A: crow-flies distance × mode speed × path factor.
    // Path factor corrects straight-line optimism (street grids, hills).
    'stage_a' => [
        'walk' => ['speed_kmh' => 4.5, 'path_factor' => 1.30],
        'bike' => ['speed_kmh' => 14.0, 'path_factor' => 1.30],
        'drive' => ['speed_kmh' => 40.0, 'path_factor' => 1.35],
    ],

];
