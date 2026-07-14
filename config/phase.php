<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 2 exit criteria (E44, E19; PRD §7.2–§7.3)
|--------------------------------------------------------------------------
|
| Set INSTRUMENT-FIRST, on purpose: the targets are written down here BEFORE the pilot
| produces the numbers to compare against. That ordering is the whole discipline — a
| threshold chosen after seeing the data is a threshold chosen to pass, and the exit read
| would then measure nothing but our own optimism (EPICS gate decisions).
|
| These answer MVP question 3: "can we interrupt at the right time?" A verdict needs a
| real pilot's Trip Mode data behind it; until then the dashboard shows current-vs-target
| with the sample size, and an honest "not enough data yet".
|
*/

return [

    'exit_criteria' => [
        // Of the pushes we sent, the share that were OPENED. Below this, we are interrupting
        // for things people do not want — the core promise is broken.
        'min_acceptance_rate' => 0.45,

        // Of the pushes we sent, the share SWIPED away. Above this, we are a nuisance.
        'max_annoyance_rate' => 0.30,

        // Of the people who turned Trip Mode ON, the share who turned it OFF mid-trip. This
        // is the sharpest signal there is — an action, not a survey — and the tightest bar.
        'max_trip_mode_abandonment' => 0.20,

        // The floor of evidence below which the read is "not yet", whatever the rates say.
        // A rate over a handful of pushes is a coincidence wearing a percentage.
        'min_sample_pushes' => 50,
    ],

];
