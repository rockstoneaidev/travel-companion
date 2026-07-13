<?php

declare(strict_types=1);

namespace App\Domain\Sources\Adapters;

use App\Domain\Places\Taxonomy\WikidataClassMap;
use App\Domain\Sources\Adapters\Concerns\BuildsCandidates;
use App\Domain\Sources\Contracts\PagedScoutSource;
use App\Domain\Sources\Contracts\ScoutSource;
use App\Domain\Sources\Data\ScoutRequest;
use App\Support\Http\Harvest;
use DateInterval;
use Illuminate\Support\Facades\Http;

/**
 * Wikidata adapter (DATA-SOURCES §2): structured knowledge — heritage and
 * nature where OSM is thin, plus the sitelink graph entity resolution joins on.
 *
 * ===========================================================================
 *  This source is chunked SPATIALLY (boxed), not by offset. That is deliberate.
 * ===========================================================================
 *
 * `normalize()` below returns ONE BINDING ROW PER (item, class) and groups them by
 * item to recover each place's P31 class list — and that class list is what decides
 * the place's TYPE. Page this by LIMIT/OFFSET and an item whose rows straddle a page
 * boundary is normalized twice, with half its classes each time. The upsert is keyed
 * on (source, external_id), so the second write wins and the place ends up typed from
 * a partial class list. That is a place that is silently the WRONG type, which is far
 * worse than a place that is missing — nobody goes looking for it.
 *
 * So Wikidata is listed in BuildRegionWorldModelJob::BOXED_SOURCES alongside OSM: each
 * box is a bbox, `wikibase:box` answers it completely, and a box is therefore a
 * semantically whole page. Memory is bounded by the box, not by the city, and no item
 * is ever split.
 *
 * (The general rule lives in {@see PagedScoutSource}:
 * page a source only where a page is semantically whole. DATAtourisme and Mérimée
 * normalize per row, so they page. This one does not.)
 */
final class WikidataAdapter implements ScoutSource
{
    use BuildsCandidates;

    public const KEY = 'wikidata';

    public const VERSION = 'v1';

    public function __construct(private readonly Harvest $harvest) {}

    private const SPARQL_URL = 'https://query.wikidata.org/sparql';

    public function supports(ScoutRequest $request): bool
    {
        return true;
    }

    public function search(ScoutRequest $request): array
    {
        // Was a bare Http::post with NO retry at all — so a single WDQS throttle or
        // 5xx lost the region's Wikidata layer and reported success. Harvest is the
        // ingest lane's policy: exponential backoff, jitter, Retry-After (conventions/09).
        $result = $this->harvest->postForm(
            self::SPARQL_URL,
            ['query' => $this->sparql($request)],
            [
                'User-Agent' => 'TravelCompanion-ingest/1.0 (rockstoneaidev@gmail.com)',
                'Accept' => 'application/sparql-results+json',
            ],
            timeout: 120,
        )->throwIfUnknown('wikidata search');

        return $result->json('results.bindings') ?? [];
    }

    public function normalize(array $raw, string $locale): array
    {
        // One binding row per (item, class); group per item first — pure array work.
        $items = [];
        foreach ($raw as $row) {
            $qid = $this->qid($row['item']['value'] ?? '');
            if ($qid === null) {
                continue;
            }

            $items[$qid] ??= ['classes' => [], 'row' => $row];
            $class = $this->qid($row['class']['value'] ?? '');
            if ($class !== null) {
                $items[$qid]['classes'][] = $class;
            }
        }

        $candidates = [];

        foreach ($items as $qid => $item) {
            $type = WikidataClassMap::map($item['classes']);
            if ($type === null) {
                continue; // a bbox pulls admin entities, streets, companies — only mapped classes matter
            }

            $row = $item['row'];
            $point = $this->parsePoint($row['coord']['value'] ?? '');
            if ($point === null) {
                continue;
            }

            $labelLocal = $row['labelLocal']['value'] ?? null;
            $labelEn = $row['labelEn']['value'] ?? null;
            $name = $labelLocal ?? $labelEn;
            if ($name === null) {
                continue;
            }

            $externalRefs = array_filter([
                'wikidata' => $qid,
                'wikipedia' => $row['localArticle']['value'] ?? null,
            ]);

            $candidates[] = $this->candidate(
                externalId: $qid,
                name: $name,
                altNames: array_filter([$labelEn ?? '']),
                lat: $point[0],
                lng: $point[1],
                type: $type,
                sourceTags: ['p31' => array_values(array_unique($item['classes']))],
                externalRefs: $externalRefs,
                language: $labelLocal !== null ? $locale : 'en',
            );
        }

        return $candidates;
    }

    public function ttl(): DateInterval
    {
        return new DateInterval('P30D');
    }

    private function sparql(ScoutRequest $request): string
    {
        $locale = $request->locale;

        return <<<SPARQL
        SELECT ?item ?coord ?class ?labelLocal ?labelEn ?localArticle WHERE {
          SERVICE wikibase:box {
            ?item wdt:P625 ?coord .
            bd:serviceParam wikibase:cornerSouthWest "Point({$request->west} {$request->south})"^^geo:wktLiteral .
            bd:serviceParam wikibase:cornerNorthEast "Point({$request->east} {$request->north})"^^geo:wktLiteral .
          }
          ?item wdt:P31 ?class .
          OPTIONAL { ?item rdfs:label ?labelLocal . FILTER(LANG(?labelLocal) = "{$locale}") }
          OPTIONAL { ?item rdfs:label ?labelEn . FILTER(LANG(?labelEn) = "en") }
          OPTIONAL { ?localArticle schema:about ?item ; schema:isPartOf <https://{$locale}.wikipedia.org/> }
        }
        LIMIT {$this->rowLimit()}
        SPARQL;
    }

    /**
     * 25,000 binding rows was a whole-region number, and it is what a whole-region JSON
     * response decoded into: tens of megabytes of PHP arrays in one `->json()` call, on
     * a box with 600 MB free.
     *
     * A BOX is a few square kilometres. 5,000 rows is already generous for one — and
     * the limit is a backstop against a pathological cell, not the plan. If a box ever
     * hits it we would rather lose the tail of one cell than the container.
     */
    private function rowLimit(): int
    {
        return 5_000;
    }

    private function qid(string $uri): ?string
    {
        return preg_match('#/(Q\d+)$#', $uri, $m) === 1 ? $m[1] : null;
    }

    /** @return array{0: float, 1: float}|null lat, lng */
    private function parsePoint(string $wkt): ?array
    {
        return preg_match('/Point\(([-0-9.]+) ([-0-9.]+)\)/', $wkt, $m) === 1
            ? [(float) $m[2], (float) $m[1]]
            : null;
    }
}
