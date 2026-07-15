<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Domain\Places\Models\PlaceImage;
use Illuminate\Support\Facades\DB;

/**
 * The photos we already had and threw away (E50).
 *
 * OSM POIs frequently carry a `wikimedia_commons` tag — a direct pointer to a Commons file
 * of the place. `OsmAdapter` keeps `wikidata` and `wikipedia` and discards this one, so a
 * place with a perfectly good photo tagged on it, but no Wikidata link, has been going
 * imageless. The tag is already in `source_tags->osm`; this reads it.
 *
 * ## Why only `wikimedia_commons`, and not the bare `image=` tag
 *
 * OSM also has an `image=<url>` tag, and it is tempting — but its URL points anywhere: a
 * photographer's own site, a Flickr page, a dead link. We cannot establish the licence of an
 * arbitrary URL, and this product does not serve a photo it cannot attribute (the same rule
 * `CommonsClient::info()` enforces). `wikimedia_commons` is always a Commons file, so its
 * licence and artist are resolvable — so that is the one we take. A bare `image` URL that
 * happens to point AT Commons is caught too; anything else is left alone.
 */
final class FetchOsmTagImages
{
    public function __construct(
        private readonly CommonsClient $commons,
    ) {}

    /**
     * @return array{candidates: int, images: int}
     */
    public function fetchBatch(int $limit = 60): array
    {
        // Places with an OSM Commons tag and no image yet. The tag lives at
        // source_tags->'osm'->>'wikimedia_commons'.
        $rows = DB::select(
            "SELECT pc.id AS place_id,
                    pc.source_tags->'osm'->>'wikimedia_commons' AS commons_tag,
                    pc.source_tags->'osm'->>'image' AS image_tag
             FROM places_core pc
             WHERE NOT EXISTS (SELECT 1 FROM place_images pi WHERE pi.place_id = pc.id AND pi.url <> '')
               AND NOT EXISTS (SELECT 1 FROM place_images pi WHERE pi.place_id = pc.id AND pi.file_name = 'osm:none')
               AND (
                   pc.source_tags->'osm'->>'wikimedia_commons' IS NOT NULL
                   OR pc.source_tags->'osm'->>'image' LIKE '%commons.wikimedia.org%'
                   OR pc.source_tags->'osm'->>'image' LIKE '%upload.wikimedia.org%'
               )
             ORDER BY pc.id
             LIMIT ?",
            [$limit],
        );

        if ($rows === []) {
            return ['candidates' => 0, 'images' => 0];
        }

        // Resolve each place's Commons file name.
        $placeByFile = [];
        foreach ($rows as $row) {
            $file = $this->fileName($row->commons_tag, $row->image_tag);
            if ($file !== null) {
                $placeByFile[$file] = $row->place_id;
            }
        }

        $filled = $this->store($placeByFile);

        // Advance the cursor past every candidate we could not fill (a tag that did not
        // resolve to a usable Commons file), so the next batch reaches new places rather
        // than re-trying the same unresolvable front of the list.
        $filledPlaceIds = array_values($filled['places']);
        foreach ($rows as $row) {
            if (! in_array($row->place_id, $filledPlaceIds, true)) {
                PlaceImage::query()->firstOrCreate(
                    ['place_id' => $row->place_id, 'file_name' => 'osm:none'],
                    ['url' => '', 'source' => 'osm_tag', 'retrieved_at' => now()],
                );
            }
        }

        return ['candidates' => count($rows), 'images' => $filled['stored']];
    }

    /** A Commons `File:…` title from the tag, or null. */
    private function fileName(?string $commonsTag, ?string $imageTag): ?string
    {
        if ($commonsTag !== null && str_starts_with($commonsTag, 'File:')) {
            return $commonsTag;
        }

        if ($commonsTag !== null && $commonsTag !== '') {
            return 'File:'.$commonsTag;   // some taggers omit the prefix
        }

        // A Commons-hosted image URL: recover the file name from its tail.
        if ($imageTag !== null && str_contains($imageTag, 'wikimedia.org')) {
            $tail = urldecode((string) preg_replace('#^.*/#', '', $imageTag));

            return $tail !== '' ? 'File:'.str_replace('_', ' ', $tail) : null;
        }

        return null;
    }

    /**
     * @param  array<string, string>  $placeByFile  file title => place_id
     */
    /** @return array{stored: int, places: array<int, string>} */
    private function store(array $placeByFile): array
    {
        $stored = 0;
        $places = [];

        foreach (array_chunk(array_keys($placeByFile), 40) as $chunk) {
            foreach ($this->commons->info($chunk) as $file => $info) {
                $placeId = $placeByFile[$file] ?? null;
                if ($placeId === null) {
                    continue;
                }

                PlaceImage::query()->updateOrCreate(
                    ['place_id' => $placeId, 'file_name' => $file],
                    [
                        'source' => 'osm_tag',
                        'url' => $info['url'],
                        'attribution' => $info['attribution'],
                        'license' => $info['license'],
                        'retrieved_at' => now(),
                    ],
                );
                $stored++;
                $places[] = $placeId;
            }
        }

        return ['stored' => $stored, 'places' => $places];
    }
}
