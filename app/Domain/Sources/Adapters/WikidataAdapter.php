<?php

declare(strict_types=1);

namespace App\Domain\Sources\Adapters;

use App\Domain\Places\Taxonomy\WikidataClassMap;
use App\Domain\Sources\Adapters\Concerns\BuildsCandidates;
use App\Domain\Sources\Contracts\ScoutSource;
use App\Domain\Sources\Data\ScoutRequest;
use DateInterval;
use Illuminate\Support\Facades\Http;

/**
 * Wikidata adapter (DATA-SOURCES §2): structured knowledge — heritage and
 * nature where OSM is thin, plus the sitelink graph entity resolution joins on.
 */
final class WikidataAdapter implements ScoutSource
{
    use BuildsCandidates;

    public const KEY = 'wikidata';

    public const VERSION = 'v1';

    private const SPARQL_URL = 'https://query.wikidata.org/sparql';

    public function supports(ScoutRequest $request): bool
    {
        return true;
    }

    public function search(ScoutRequest $request): array
    {
        $response = Http::timeout(120)
            ->withHeaders([
                'User-Agent' => 'TravelCompanion-ingest/1.0 (rockstoneaidev@gmail.com)',
                'Accept' => 'application/sparql-results+json',
            ])
            ->asForm()
            ->post(self::SPARQL_URL, ['query' => $this->sparql($request)]);

        $response->throw();

        return $response->json('results.bindings') ?? [];
    }

    public function normalize(array $raw): array
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

            $labelSv = $row['labelSv']['value'] ?? null;
            $labelEn = $row['labelEn']['value'] ?? null;
            $name = $labelSv ?? $labelEn;
            if ($name === null) {
                continue;
            }

            $externalRefs = array_filter([
                'wikidata' => $qid,
                'wikipedia_sv' => $row['svArticle']['value'] ?? null,
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
                language: $labelSv !== null ? 'sv' : 'en',
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
        return <<<SPARQL
        SELECT ?item ?coord ?class ?labelSv ?labelEn ?svArticle WHERE {
          SERVICE wikibase:box {
            ?item wdt:P625 ?coord .
            bd:serviceParam wikibase:cornerSouthWest "Point({$request->west} {$request->south})"^^geo:wktLiteral .
            bd:serviceParam wikibase:cornerNorthEast "Point({$request->east} {$request->north})"^^geo:wktLiteral .
          }
          ?item wdt:P31 ?class .
          OPTIONAL { ?item rdfs:label ?labelSv . FILTER(LANG(?labelSv) = "sv") }
          OPTIONAL { ?item rdfs:label ?labelEn . FILTER(LANG(?labelEn) = "en") }
          OPTIONAL { ?svArticle schema:about ?item ; schema:isPartOf <https://sv.wikipedia.org/> }
        }
        LIMIT 25000
        SPARQL;
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
