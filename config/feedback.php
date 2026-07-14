<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Feedback — the signals the taste model actually learns from
|--------------------------------------------------------------------------
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Passive visit detection (E37; PRD §7.1, §13.3)
    |--------------------------------------------------------------------------
    |
    | The golden label goes dense. Three numbers are the whole detector, and each one is a
    | judgement about what "went there" means — which is why they live here, versioned with
    | the detector, rather than as literals inside a query.
    |
    */
    'visit_detection' => [
        // How far you may drift and still be standing in the same place. Larger than GPS
        // noise in a street canyon; smaller than the next building along.
        'dwell_radius_m' => 120,

        // Long enough that a red light, a glance at the map, and a stop to take a
        // photograph are not a visit. Short enough that a look inside a church is.
        'min_dwell_minutes' => 10,

        // How close a dwell must be to the thing we recommended before we are willing to
        // say you went to it. Deliberately tighter than the dwell radius: standing vaguely
        // near a museum is not visiting the museum.
        'match_radius_m' => 100,
    ],

];
