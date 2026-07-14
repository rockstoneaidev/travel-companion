<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Source feeds that are configured per region, not compiled in
|--------------------------------------------------------------------------
|
| Some sources are not one global API but a DIFFERENT feed per place: the local paper in
| Nice is Nice-Matin, in Dijon it is Le Bien Public. The adapter is one; the feeds are
| many, and which feed serves which region is an operational fact, not a code fact.
|
| Empty by default, and that is the honest state: with no feed for a region, the app
| simply has no local-alert layer there and says nothing — degradation, never a crash
| (conventions/09). A region gets alerts when somebody adds its feed here.
|
*/

return [

    /*
     * Local news / disruption feeds (E39). Keyed by region key (r5-… or a catalogue key).
     * Each entry: an RSS/Atom URL, the attribution string the licence requires, and the
     * source key used on the evidence row.
     *
     *   'r5-8508f62ffffffff' => [
     *       ['url' => 'https://www.example-news.se/feed/', 'source' => 'news_local',
     *        'attribution' => 'Example-Posten'],
     *   ],
     *
     * No feeds are shipped: pilot regions get theirs added deliberately, after their terms
     * are read (DATA-SOURCES §3 — headlines/RSS often free, full text paywalled, respect it).
     */
    'news_feeds' => [
        //
    ],

];
