<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Support\Http\Harvest;

/**
 * The Wikimedia Commons calls the image fetchers share (E50).
 *
 * Two operations, and between them they unlock most of the coverage the Wikidata-only path
 * (`FetchCommonsImages`) was structurally blind to:
 *
 *   - `info()` — a File name → its thumbnail URL, artist and licence. The licence discipline
 *     lives here: a file whose `extmetadata` yields no usable image is dropped, never served
 *     unattributed. Every path that ends in a Commons file funnels through this, so the
 *     attribution guarantee holds no matter which fetcher found it.
 *
 *   - `geosearch()` — files geotagged NEAR a coordinate. This is the single biggest lever:
 *     it needs no Wikidata link, no Wikipedia article, no OSM tag — just a place's location,
 *     which every place has. Most of the OSM long tail is reachable only this way.
 */
final class CommonsClient
{
    private const API = 'https://commons.wikimedia.org/w/api.php';

    private const USER_AGENT = 'TravelCompanion-photos/1.0 (rockstoneaidev@gmail.com)';

    public function __construct(private readonly Harvest $harvest) {}

    /**
     * File names near a coordinate, nearest first (Commons `list=geosearch`, namespace 6).
     *
     * @return list<string> Commons file titles ("File:…"), ordered by distance
     */
    public function geosearch(float $lat, float $lng, int $radiusMeters, int $limit): array
    {
        $response = $this->harvest->get(
            self::API,
            [
                'action' => 'query',
                'list' => 'geosearch',
                'gscoord' => "{$lat}|{$lng}",
                'gsradius' => $radiusMeters,   // metres; Commons caps at 10km
                'gslimit' => $limit,
                'gsnamespace' => 6,            // File: — photos, not gallery pages
                'gsprimary' => 'all',
                'format' => 'json',
                'formatversion' => 2,
            ],
            ['User-Agent' => self::USER_AGENT],
            timeout: 30,
        )->throwIfUnknown('commons geosearch');

        $titles = [];
        foreach ($response->json('query.geosearch') ?? [] as $hit) {
            if (isset($hit['title'])) {
                $titles[] = $hit['title'];   // already "File:…"
            }
        }

        return $titles;
    }

    /**
     * File name → its usable image, or dropped.
     *
     * @param  list<string>  $fileNames
     * @return array<string, array{url: string, attribution: ?string, license: ?string}>
     */
    public function info(array $fileNames): array
    {
        if ($fileNames === []) {
            return [];
        }

        $response = $this->harvest->get(
            self::API,
            [
                'action' => 'query',
                'titles' => implode('|', $fileNames),
                'prop' => 'imageinfo',
                'iiprop' => 'url|extmetadata',
                'iiurlwidth' => 800,
                'format' => 'json',
                'formatversion' => 2,
            ],
            ['User-Agent' => self::USER_AGENT],
            timeout: 60,
        )->throwIfUnknown('commons imageinfo');

        $out = [];
        foreach ($response->json('query.pages') ?? [] as $page) {
            $info = $page['imageinfo'][0] ?? null;

            // No renderable thumbnail → not an image we can show. Skip, never serve blank.
            if ($info === null || ! isset($info['thumburl'])) {
                continue;
            }

            $meta = $info['extmetadata'] ?? [];
            $artist = isset($meta['Artist']['value']) ? trim(strip_tags($meta['Artist']['value'])) : null;

            $out[$page['title']] = [
                'url' => $info['thumburl'],
                'attribution' => $artist !== null && $artist !== '' ? mb_substr($artist, 0, 500) : null,
                'license' => $meta['LicenseShortName']['value'] ?? null,
            ];
        }

        return $out;
    }
}
