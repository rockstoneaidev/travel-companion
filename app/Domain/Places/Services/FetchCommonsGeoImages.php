<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Domain\Places\Models\PlaceImage;
use Illuminate\Support\Facades\DB;

/**
 * A photo taken AT the place, found by where the place is (E50).
 *
 * The single biggest coverage lever, because it needs nothing but a coordinate — no Wikidata
 * link, no Wikipedia article, no OSM tag. It asks Commons for files GEOTAGGED near the place
 * and takes the nearest usable one. Most of the OSM long tail — the neighbourhood park, the
 * minor church, the viewpoint nobody wrote an article about — is reachable only this way.
 *
 * ## The honesty knob is the radius, and it is deliberately tight
 *
 * A geotagged file 400 m away is probably a photo of something else. So the search radius is
 * small: close enough that "a photo geotagged here" and "a photo OF this" are the same claim.
 * We take the nearest file that resolves, and if none is within the radius, the place keeps
 * its paper stripe — an honest absence beats a photo of the building next door.
 *
 * Runs LAST of the image fetchers, so a place that already got a picture from its Wikidata
 * item, its OSM tag or its Wikipedia article is not re-fetched — geosearch is the catch-all
 * for everything the linked sources could not reach.
 */
final class FetchCommonsGeoImages
{
    public function __construct(
        private readonly CommonsClient $commons,
    ) {}

    /**
     * @return array{candidates: int, images: int}
     */
    public function fetchBatch(int $limit = 40): array
    {
        $radius = (int) config('places.images.geosearch_radius_meters');
        $perPlace = (int) config('places.images.geosearch_candidates');

        // Places with no image, no prior geosearch, and a location. The `geo:none` marker is
        // the cursor: without it, ORDER BY id LIMIT would return the same front-of-list
        // imageless parks every batch and never reach the places further down that DO have a
        // photo nearby. A distinct marker (not the '' that FetchCommonsImages uses for its
        // Wikidata path) so the two sources' "I looked and found nothing" never collide.
        $rows = DB::select(
            "SELECT pc.id AS place_id,
                    ST_Y(pc.location::geometry) AS lat,
                    ST_X(pc.location::geometry) AS lng
             FROM places_core pc
             WHERE NOT EXISTS (SELECT 1 FROM place_images pi WHERE pi.place_id = pc.id AND pi.url <> '')
               AND NOT EXISTS (SELECT 1 FROM place_images pi WHERE pi.place_id = pc.id AND pi.file_name = 'geo:none')
             ORDER BY pc.id
             LIMIT ?",
            [$limit],
        );

        if ($rows === []) {
            return ['candidates' => 0, 'images' => 0];
        }

        $stored = 0;

        foreach ($rows as $row) {
            $files = $this->commons->geosearch((float) $row->lat, (float) $row->lng, $radius, $perPlace);

            $found = false;

            // Nearest first (geosearch order); take the first that resolves to a real image.
            foreach ($files === [] ? [] : $this->commons->info($files) as $file => $info) {
                PlaceImage::query()->updateOrCreate(
                    ['place_id' => $row->place_id, 'file_name' => $file],
                    [
                        'source' => 'commons_geo',
                        'url' => $info['url'],
                        'attribution' => $info['attribution'],
                        'license' => $info['license'],
                        'retrieved_at' => now(),
                    ],
                );
                $stored++;
                $found = true;
                break;   // one photo per place is enough for a card
            }

            if (! $found) {
                // Nothing usable nearby. Mark it looked-at so the cursor advances — an honest
                // paper stripe beats a photo of the building next door, and beats re-searching
                // this park on every run forever.
                PlaceImage::query()->firstOrCreate(
                    ['place_id' => $row->place_id, 'file_name' => 'geo:none'],
                    ['url' => '', 'source' => 'commons_geo', 'retrieved_at' => now()],
                );
            }
        }

        return ['candidates' => count($rows), 'images' => $stored];
    }
}
