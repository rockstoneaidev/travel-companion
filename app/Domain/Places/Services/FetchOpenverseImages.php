<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Domain\Places\Models\PlaceImage;
use App\Support\Http\Harvest;
use Illuminate\Support\Facades\DB;

/**
 * The CC pool, without Flickr's PRO gate (E50 round two) — and the lowest-confidence source
 * we have, on purpose.
 *
 * Openverse aggregates CC-licensed images (Flickr, Commons, museums) behind a free,
 * keyless API. That is its appeal and its danger: it is searched by TEXT, not coordinate, so
 * a query for a place name can return a photo of a DIFFERENT place with the same name — the
 * exact "is this actually here" risk this product refuses everywhere else.
 *
 * ## So it is guarded, and it runs dead last
 *
 * Two guards keep it honest, and both must pass:
 *
 *   1. **Distinctive names only.** A place whose name is short or generic ("Torget", "Café")
 *      is not searched at all — the match would be a coin flip. Only names long enough to be
 *      specific get a query.
 *   2. **The result title must contain the place name.** We do not trust Openverse's relevance
 *      ranking; we require the returned image to actually say it is this place. A result that
 *      merely "matched" the query is discarded.
 *
 * Even then it ranks BELOW every coordinate-based source (Mapillary, GeoSearch), because a
 * name match is a weaker claim than a geotag. It exists to rescue the distinctively-named
 * long tail that has no photo anywhere geotagged — and when in doubt it takes nothing, because
 * a paper stripe is more honest than a photo of the wrong building.
 */
final class FetchOpenverseImages
{
    private const API = 'https://api.openverse.org/v1/images/';

    private const USER_AGENT = 'TravelCompanion-photos/1.0 (rockstoneaidev@gmail.com)';

    public function __construct(private readonly Harvest $harvest) {}

    /**
     * @return array{candidates: int, images: int}
     */
    public function fetchBatch(int $limit = 30): array
    {
        $minNameLength = (int) config('places.images.openverse_min_name_length');

        // Distinctive names only, no image, not already searched.
        $rows = DB::select(
            "SELECT pc.id AS place_id, pc.name
             FROM places_core pc
             WHERE NOT EXISTS (SELECT 1 FROM place_images pi WHERE pi.place_id = pc.id AND pi.url <> '')
               AND NOT EXISTS (SELECT 1 FROM place_images pi WHERE pi.place_id = pc.id AND pi.file_name = 'openverse:none')
               AND char_length(pc.name) >= ?
             ORDER BY pc.id
             LIMIT ?",
            [$minNameLength, $limit],
        );

        if ($rows === []) {
            return ['candidates' => 0, 'images' => 0];
        }

        $stored = 0;

        foreach ($rows as $row) {
            $image = $this->match((string) $row->name);

            if ($image === null) {
                PlaceImage::query()->firstOrCreate(
                    ['place_id' => $row->place_id, 'file_name' => 'openverse:none'],
                    ['url' => '', 'source' => 'openverse', 'retrieved_at' => now()],
                );

                continue;
            }

            PlaceImage::query()->updateOrCreate(
                ['place_id' => $row->place_id, 'file_name' => 'openverse:'.$image['id']],
                [
                    'source' => 'openverse',
                    'url' => $image['url'],
                    'attribution' => $image['attribution'],
                    'license' => $image['license'],
                    'retrieved_at' => now(),
                ],
            );
            $stored++;
        }

        return ['candidates' => count($rows), 'images' => $stored];
    }

    /**
     * The first Openverse result whose title actually names this place, or null.
     *
     * @return array{id: string, url: string, attribution: ?string, license: ?string}|null
     */
    private function match(string $name): ?array
    {
        $response = $this->harvest->get(
            self::API,
            [
                'q' => $name,
                'license_type' => 'all-cc',   // CC only — everything here must be attributable
                'page_size' => 5,
            ],
            ['User-Agent' => self::USER_AGENT],
            timeout: 20,
        )->throwIfUnknown('openverse images');

        $needle = mb_strtolower($name);

        foreach ($response->json('results') ?? [] as $result) {
            $title = mb_strtolower((string) ($result['title'] ?? ''));
            $url = $result['url'] ?? $result['thumbnail'] ?? null;

            // The guard: the result must SAY it is this place, and carry a licence. Openverse's
            // own relevance is not enough — a coincidental keyword match is exactly the wrong
            // photo we are avoiding.
            if ($url === null || ! str_contains($title, $needle) || ($result['license'] ?? null) === null) {
                continue;
            }

            $version = isset($result['license_version']) ? ' '.$result['license_version'] : '';

            return [
                'id' => (string) ($result['id'] ?? md5((string) $url)),
                'url' => (string) $url,
                'attribution' => isset($result['creator']) ? mb_substr((string) $result['creator'], 0, 500) : null,
                'license' => strtoupper((string) $result['license']).$version,
            ];
        }

        return null;
    }
}
