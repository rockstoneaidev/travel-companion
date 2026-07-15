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
    ],

];
