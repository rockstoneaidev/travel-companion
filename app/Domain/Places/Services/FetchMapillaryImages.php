<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Domain\Places\Models\PlaceImage;
use App\Support\Http\Harvest;
use Illuminate\Support\Facades\DB;

/**
 * Street-level imagery for places nobody framed a nice photo of (E50 round two).
 *
 * ## Coordinate-based, so it stays honest
 *
 * Mapillary is crowd-driven street photography, geotagged by definition. We ask for images
 * inside a small bbox around a place and take the nearest — so "a Mapillary image here" is a
 * photo of *this location*, not a keyword guess. That is the same honesty bar Commons
 * GeoSearch clears, which is why this ranks alongside it rather than with the name-matched
 * fallback.
 *
 * The trade is style, not truth: a street frame of the building is less of a hero shot than a
 * photographer's composed Commons image, so this runs AFTER geosearch — it fills the tail
 * geosearch could not, for the squares, monuments and streets a car drove past but no one
 * photographed for its own sake. Honest and present beats absent.
 *
 * ## Degrades to nothing without a token
 *
 * Mapillary's API requires a free access token. With none set, this returns zero and writes
 * nothing — exactly like Google Routes without a key. So it is a config flip once you
 * register (`MAPILLARY_TOKEN`), and the pipeline is unaffected until then.
 */
final class FetchMapillaryImages
{
    private const API = 'https://graph.mapillary.com/images';

    private const USER_AGENT = 'TravelCompanion-photos/1.0 (rockstoneaidev@gmail.com)';

    public function __construct(private readonly Harvest $harvest) {}

    /**
     * @return array{candidates: int, images: int}
     */
    public function fetchBatch(int $limit = 40): array
    {
        $token = (string) config('services.mapillary.token');

        if ($token === '') {
            return ['candidates' => 0, 'images' => 0];   // not configured — a supported state
        }

        $rows = DB::select(
            "SELECT pc.id AS place_id,
                    ST_Y(pc.location::geometry) AS lat,
                    ST_X(pc.location::geometry) AS lng
             FROM places_core pc
             WHERE NOT EXISTS (SELECT 1 FROM place_images pi WHERE pi.place_id = pc.id AND pi.url <> '')
               AND NOT EXISTS (SELECT 1 FROM place_images pi WHERE pi.place_id = pc.id AND pi.file_name = 'mapillary:none')
             ORDER BY pc.id
             LIMIT ?",
            [$limit],
        );

        if ($rows === []) {
            return ['candidates' => 0, 'images' => 0];
        }

        $radiusDeg = (float) config('places.images.mapillary_radius_degrees');
        $stored = 0;

        foreach ($rows as $row) {
            $image = $this->nearest((float) $row->lat, (float) $row->lng, $radiusDeg, $token);

            if ($image === null) {
                PlaceImage::query()->firstOrCreate(
                    ['place_id' => $row->place_id, 'file_name' => 'mapillary:none'],
                    ['url' => '', 'source' => 'mapillary', 'retrieved_at' => now()],
                );

                continue;
            }

            PlaceImage::query()->updateOrCreate(
                ['place_id' => $row->place_id, 'file_name' => 'mapillary:'.$image['id']],
                [
                    'source' => 'mapillary',
                    'url' => $image['url'],
                    // Mapillary imagery is CC-BY-SA 4.0; attribution is to the platform's
                    // contributors, which is the licence's requirement.
                    'attribution' => '© Mapillary contributors',
                    'license' => 'CC BY-SA 4.0',
                    'retrieved_at' => now(),
                ],
            );
            $stored++;
        }

        return ['candidates' => count($rows), 'images' => $stored];
    }

    /**
     * The nearest street image in a small box around the place, or null.
     *
     * @return array{id: string, url: string}|null
     */
    private function nearest(float $lat, float $lng, float $radiusDeg, string $token): ?array
    {
        $bbox = implode(',', [$lng - $radiusDeg, $lat - $radiusDeg, $lng + $radiusDeg, $lat + $radiusDeg]);

        $response = $this->harvest->get(
            self::API,
            [
                'fields' => 'id,thumb_1024_url',
                'bbox' => $bbox,
                'limit' => 1,   // one is enough for a card; the API returns them by relevance
                'access_token' => $token,
            ],
            ['User-Agent' => self::USER_AGENT],
            timeout: 20,
        )->throwIfUnknown('mapillary images');

        $image = $response->json('data.0');

        if ($image === null || ! isset($image['thumb_1024_url'], $image['id'])) {
            return null;
        }

        return ['id' => (string) $image['id'], 'url' => (string) $image['thumb_1024_url']];
    }
}
