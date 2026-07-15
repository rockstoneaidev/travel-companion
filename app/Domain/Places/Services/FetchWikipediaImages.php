<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Domain\Places\Models\PlaceImage;
use App\Support\Http\Harvest;
use Illuminate\Support\Facades\DB;

/**
 * A place's Wikipedia article has a lead image, even when Wikidata's P18 does not (E50).
 *
 * `place_source_ids` carries a `wikipedia` link — stored as `lang:Title` (e.g.
 * `sv:Sankt Ansgars kyrka, Bromma`). A place can have that article, and the article can have
 * a lead photo, while the Wikidata item it links to has no `P18` at all — so the Wikidata
 * path (`FetchCommonsImages`) misses it and this catches it.
 *
 * We ask the article's own language Wikipedia for `prop=pageimages` and take the FILE NAME,
 * not the thumbnail URL — then resolve that file through Commons for its licence and artist.
 * The extra hop is the point: an image we cannot attribute is an image we do not serve, and
 * `pageimages` alone gives a URL with no licence beside it.
 */
final class FetchWikipediaImages
{
    private const USER_AGENT = 'TravelCompanion-photos/1.0 (rockstoneaidev@gmail.com)';

    public function __construct(
        private readonly Harvest $harvest,
        private readonly CommonsClient $commons,
    ) {}

    /**
     * @return array{candidates: int, images: int}
     */
    public function fetchBatch(int $limit = 40): array
    {
        $rows = DB::select(
            "SELECT psi.place_id, psi.external_id AS wp
             FROM place_source_ids psi
             WHERE psi.source = 'wikipedia'
               AND NOT EXISTS (SELECT 1 FROM place_images pi WHERE pi.place_id = psi.place_id AND pi.url <> '')
               AND NOT EXISTS (SELECT 1 FROM place_images pi WHERE pi.place_id = psi.place_id AND pi.file_name = 'wp:none')
             ORDER BY psi.place_id
             LIMIT ?",
            [$limit],
        );

        if ($rows === []) {
            return ['candidates' => 0, 'images' => 0];
        }

        // Group by language wiki — one API call per language, titles batched.
        $byLang = [];         // lang => [title => place_id]
        foreach ($rows as $row) {
            [$lang, $title] = array_pad(explode(':', (string) $row->wp, 2), 2, null);
            if ($title === null || $title === '') {
                continue;
            }
            $byLang[$lang][$title] = $row->place_id;
        }

        $placeByFile = [];    // Commons "File:…" => place_id

        foreach ($byLang as $lang => $titles) {
            foreach ($this->leadFiles($lang, array_keys($titles)) as $title => $file) {
                $placeId = $titles[$title] ?? null;
                if ($placeId !== null) {
                    $placeByFile[$file] = $placeId;
                }
            }
        }

        $filled = $this->store($placeByFile);

        // Advance the cursor past every wikipedia-linked place we could not fill (an article
        // with no lead image, or a lead image that would not resolve on Commons).
        foreach ($rows as $row) {
            if (! in_array($row->place_id, $filled['places'], true)) {
                PlaceImage::query()->firstOrCreate(
                    ['place_id' => $row->place_id, 'file_name' => 'wp:none'],
                    ['url' => '', 'source' => 'wikipedia', 'retrieved_at' => now()],
                );
            }
        }

        return ['candidates' => count($rows), 'images' => $filled['stored']];
    }

    /**
     * article titles → their lead-image Commons file names.
     *
     * @param  list<string>  $titles
     * @return array<string, string> article title => "File:…"
     */
    private function leadFiles(string $lang, array $titles): array
    {
        $response = $this->harvest->get(
            "https://{$lang}.wikipedia.org/w/api.php",
            [
                'action' => 'query',
                'titles' => implode('|', array_slice($titles, 0, 50)),
                'prop' => 'pageimages',
                'piprop' => 'name',       // the FILE NAME, so we can resolve its licence
                'format' => 'json',
                'formatversion' => 2,
            ],
            ['User-Agent' => self::USER_AGENT],
            timeout: 30,
        )->throwIfUnknown('wikipedia pageimages');

        $out = [];
        foreach ($response->json('query.pages') ?? [] as $page) {
            $name = $page['pageimage'] ?? null;
            $title = $page['title'] ?? null;
            if ($name !== null && $title !== null) {
                $out[$title] = 'File:'.str_replace('_', ' ', $name);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $placeByFile
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
                        'source' => 'wikipedia',
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
