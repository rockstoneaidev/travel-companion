<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Stage-B routing (PRD §10, E43)
|--------------------------------------------------------------------------
|
| The Routing port is what makes self-hosting a SWAP, not a rewrite. This file is the
| switch. It is deliberately off by default: E43 is COST-TRIGGERED, not calendar-triggered
| — you flip it when the cost ledger (E24/E25) says Google Routes spend justifies the ops
| burden of running OSRM on the OSM extract, and not a day before.
|
*/

return [

    /*
     * Which engine draws the numbers a user actually sees.
     *
     *   'google' — Google Routes (the default, and correct until the ledger says otherwise).
     *   'osrm'   — self-hosted OSRM on our own extract, with Google kept as a live fallback.
     *
     * The fallback is the whole safety of flipping this: if OSRM is unreachable, a served
     * item still gets a real number rather than falling back to the ±30% estimator. A route
     * a user is about to walk should be right.
     */
    'driver' => env('ROUTING_DRIVER', 'google'),

    'osrm' => [
        /*
         * ONE URL PER TRAVEL MODE — OSRM serves a single profile per process (SERVER-DEPLOYMENT).
         * A mode left blank stays on Google via FallbackRouting, so we can self-host the walking
         * pilot first (set OSRM_URL_FOOT) and bring bike/drive across later, one at a time.
         */
        'urls' => [
            'foot' => env('OSRM_URL_FOOT', ''),
            'bicycle' => env('OSRM_URL_BIKE', ''),
            'driving' => env('OSRM_URL_DRIVE', ''),
        ],

        'timeout_seconds' => 4,

        // Keep Google as a live fallback when OSRM cannot answer. On by default because the
        // entire point of the fallback is that flipping to self-hosted is low-risk — turn it
        // off only once OSRM has earned that trust on real traffic.
        'google_fallback' => env('OSRM_GOOGLE_FALLBACK', true),
    ],

];
