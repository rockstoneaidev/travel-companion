<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

/**
 * Every free way we have to find a photo of a place, in order of confidence (E50).
 *
 * The photos phase used to be ONE path — Wikidata `P18` — which reaches only places that
 * carry a Wikidata link, and left coverage at 2.4%. This runs the four free sources in a
 * deliberate order, each catching what the ones before it could not:
 *
 *   1. Wikidata P18       — the place's own item names its image. Most confident: the image
 *                           is asserted to BE this place.
 *   2. OSM `wikimedia_commons` tag — a Commons file a mapper attached to the place. Almost as
 *                           confident, and it was already in our data, discarded.
 *   3. Wikipedia lead image — the place's article has a photo the Wikidata item did not.
 *   4. Commons GeoSearch  — a photo geotagged AT the place. Coordinate-based, so still an
 *                           honest "here"; the widest net of the Commons paths.
 *   5. Mapillary          — street-level imagery at the coordinate (E50 round two). Honest
 *                           "here" too, lower only in STYLE (a street frame, not a hero shot);
 *                           degrades to nothing without a token.
 *   6. Openverse          — the CC pool, searched by NAME. Lowest confidence and guarded hard
 *                           (distinctive names, title must match), so it runs dead last.
 *
 * The order matters for cost as much as confidence: each source excludes places that already
 * have a real image, so the expensive per-place geosearch only runs on the genuine long tail
 * the linked sources never had a hope of covering.
 */
final class FetchPlaceImages
{
    public function __construct(
        private readonly FetchCommonsImages $wikidata,
        private readonly FetchOsmTagImages $osmTags,
        private readonly FetchWikipediaImages $wikipedia,
        private readonly FetchCommonsGeoImages $geo,
        private readonly FetchMapillaryImages $mapillary,
        private readonly FetchOpenverseImages $openverse,
    ) {}

    /**
     * One batch across all sources. The phase loops while any source found an image and stops
     * when a whole pass finds none — same rule as before, now over four wells instead of one.
     *
     * @return array{candidates: int, images: int}
     */
    public function fetchBatch(): array
    {
        $candidates = 0;
        $images = 0;

        // Order is confidence, high to low. The coordinate-based sources (geosearch,
        // Mapillary) come before the name-based one (Openverse), because a geotag is a
        // stronger claim than a title match — and each excludes places already served, so the
        // weaker sources only ever run on the tail the stronger ones could not reach (E50).
        foreach ([$this->wikidata, $this->osmTags, $this->wikipedia, $this->geo, $this->mapillary, $this->openverse] as $source) {
            $result = $source->fetchBatch();
            $candidates += $result['candidates'];
            $images += $result['images'];
        }

        return ['candidates' => $candidates, 'images' => $images];
    }
}
