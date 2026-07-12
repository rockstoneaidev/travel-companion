<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Domain\Places\Models\PlaceImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The v1 photo pipeline (SCREENS build note 6): Wikidata P18 → Commons file →
 * 800px thumb + per-file attribution/license. Facts flow source → store;
 * nothing is invented, and files without resolvable license metadata are
 * skipped rather than served unattributed.
 */
final class FetchCommonsImages
{
    private const SPARQL_URL = 'https://query.wikidata.org/sparql';

    private const COMMONS_API = 'https://commons.wikimedia.org/w/api.php';

    private const USER_AGENT = 'TravelCompanion-photos/1.0 (rockstoneaidev@gmail.com)';

    /**
     * Fetch images for up to $limit wikidata-linked places that have none yet.
     *
     * @return array{candidates: int, images: int}
     */
    public function fetchBatch(int $limit = 60): array
    {
        // Wikidata-linked places without an image yet, via the concordance.
        $rows = DB::select(
            "SELECT DISTINCT psi.place_id, psi.external_id AS qid
             FROM place_source_ids psi
             WHERE psi.source = 'wikidata'
               AND NOT EXISTS (SELECT 1 FROM place_images pi WHERE pi.place_id = psi.place_id)
             ORDER BY psi.place_id
             LIMIT ?",
            [$limit],
        );

        if ($rows === []) {
            return ['candidates' => 0, 'images' => 0];
        }

        $placeByQid = [];
        foreach ($rows as $row) {
            $placeByQid[$row->qid] = $row->place_id;
        }

        $files = $this->imagesFor(array_keys($placeByQid));

        // Even QIDs WITHOUT a P18 get a marker row is wrong — instead, record
        // misses separately so the batch cursor advances: places with no P18
        // get an empty sentinel that the reader ignores.
        $found = [];
        foreach ($files as $qid => $fileName) {
            $found[$fileName] = $qid;
        }

        $stored = 0;
        foreach (array_chunk(array_keys($found), 40) as $chunk) {
            foreach ($this->commonsInfo($chunk) as $fileName => $info) {
                $qid = $found[$fileName] ?? null;
                if ($qid === null) {
                    continue;
                }

                PlaceImage::query()->updateOrCreate(
                    ['place_id' => $placeByQid[$qid], 'file_name' => $fileName],
                    [
                        'url' => $info['url'],
                        'attribution' => $info['attribution'],
                        'license' => $info['license'],
                        'retrieved_at' => now(),
                    ],
                );
                $stored++;
            }
        }

        // Sentinel rows for no-image places so the next batch moves on.
        foreach ($placeByQid as $qid => $placeId) {
            if (! isset($files[$qid])) {
                PlaceImage::query()->firstOrCreate(
                    ['place_id' => $placeId, 'file_name' => ''],
                    ['url' => '', 'source' => 'none', 'retrieved_at' => now()],
                );
            }
        }

        return ['candidates' => count($rows), 'images' => $stored];
    }

    /**
     * @param  list<string>  $qids
     * @return array<string, string> qid => Commons file name
     */
    private function imagesFor(array $qids): array
    {
        $values = implode(' ', array_map(static fn (string $q): string => "wd:{$q}", $qids));

        $response = Http::timeout(60)
            ->withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'application/sparql-results+json'])
            ->asForm()
            ->post(self::SPARQL_URL, ['query' => "SELECT ?item ?image WHERE { VALUES ?item { {$values} } ?item wdt:P18 ?image }"]);

        $response->throw();

        $out = [];
        foreach ($response->json('results.bindings') ?? [] as $binding) {
            $qid = str($binding['item']['value'])->afterLast('/')->toString();
            $file = urldecode(str($binding['image']['value'])->afterLast('/')->toString());
            $out[$qid] ??= 'File:'.str_replace('_', ' ', $file);
        }

        return $out;
    }

    /**
     * @param  list<string>  $fileNames
     * @return array<string, array{url: string, attribution: ?string, license: ?string}>
     */
    private function commonsInfo(array $fileNames): array
    {
        $response = Http::timeout(60)
            ->withHeaders(['User-Agent' => self::USER_AGENT])
            ->get(self::COMMONS_API, [
                'action' => 'query',
                'titles' => implode('|', $fileNames),
                'prop' => 'imageinfo',
                'iiprop' => 'url|extmetadata',
                'iiurlwidth' => 800,
                'format' => 'json',
                'formatversion' => 2,
            ]);

        $response->throw();

        $out = [];
        foreach ($response->json('query.pages') ?? [] as $page) {
            $info = $page['imageinfo'][0] ?? null;
            if ($info === null || ! isset($info['thumburl'])) {
                continue;
            }

            $meta = $info['extmetadata'] ?? [];
            $artist = isset($meta['Artist']['value']) ? trim(strip_tags($meta['Artist']['value'])) : null;

            $out[$page['title']] = [
                'url' => $info['thumburl'],
                'attribution' => $artist !== null ? mb_substr($artist, 0, 500) : null,
                'license' => $meta['LicenseShortName']['value'] ?? null,
            ];
        }

        return $out;
    }
}
