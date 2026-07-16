<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Image coverage (E50)
    |--------------------------------------------------------------------------
    */
    'images' => [
        // Commons GeoSearch radius. Tight on purpose: a file geotagged 400 m away is
        // probably a photo of something else, and "a photo geotagged here" must mean the
        // same thing as "a photo OF this" or the coverage is bought with quiet lies.
        'geosearch_radius_meters' => 120,
        // How many nearby files to consider before giving up on a place (nearest first).
        'geosearch_candidates' => 6,

        // Mapillary bbox half-size in degrees (~0.0007° ≈ 60-80m at Nordic latitudes). Tight,
        // for the same reason geosearch is: a street frame must be OF here, not the next block.
        'mapillary_radius_degrees' => 0.0007,

        // Openverse only searches names this long or longer — short/generic names ("Torget")
        // would coin-flip the match, and a wrong photo is worse than none.
        'openverse_min_name_length' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Opening-hours verification (E16, E50 cost lever)
    |--------------------------------------------------------------------------
    |
    | Before paying Google to verify a place is open, we read OSM's own opening_hours tag.
    | OSM hours are LOCAL, so we evaluate them in the place's timezone — and the whole pilot
    | is Central European, so one region timezone is exact. When the product serves a place
    | outside CET, this MUST become a per-place timezone (OsmOpeningHours says so too).
    |
    */
    'hours' => [
        'assumed_timezone' => env('HOURS_ASSUMED_TIMEZONE', 'Europe/Stockholm'),
    ],

];
