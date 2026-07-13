<?php

declare(strict_types=1);

namespace App\Domain\Sources\Adapters;

use App\Domain\Places\Taxonomy\DatatourismeTypeMap;
use App\Domain\Sources\Adapters\Concerns\BuildsCandidates;
use App\Domain\Sources\Contracts\PagedScoutSource;
use App\Domain\Sources\Data\ScoutRequest;
use App\Support\Http\Harvest;
use DateInterval;
use RuntimeException;

/**
 * DATAtourisme — the national French open-data platform aggregating the POIs of
 * every regional and departmental tourism board (DATA-SOURCES §7).
 *
 * "The single highest-value source for a French launch: exactly the tourism-board
 * gold of Layer 3, already aggregated, already structured, legally ours to
 * persist." Licence Ouverte (Etalab), so it is open core and takes part in
 * entity resolution.
 *
 * Credibility Tier A: a tourism board is an official body writing about its own
 * territory. But note what that does and does not license — DATAtourisme can
 * establish that a place *exists*; it cannot be trusted for opening hours, which
 * are a live claim with their own TTL (conventions/09).
 *
 * Needs an API key (free, on request). Without one the source degrades to
 * "unsupported" rather than failing the region — the same coverage-honesty rule
 * the Overture extract follows.
 */
final class DatatourismeAdapter implements PagedScoutSource
{
    use BuildsCandidates;

    public const KEY = 'datatourisme';

    public const VERSION = 'v1';

    public function __construct(private readonly Harvest $harvest) {}

    private const BASE = 'https://api.datatourisme.fr/v1/catalog';

    // The API pages at 20. Paris alone holds 4,285 POIs, so 120 pages silently
    // truncated it to 2,400 — a cap low enough to bite is worse than no cap,
    // because the region looks fully ingested. 500 pages = 10,000 POIs, which is
    // well clear of any corridor city, and search() throws rather than truncate
    // if a region ever exceeds it.
    private const MAX_PAGES = 500;

    public function supports(ScoutRequest $request): bool
    {
        return $request->locale === 'fr' && $this->key() !== null;
    }

    /**
     * The region, one API page at a time — never the whole thing at once.
     *
     * `search()` below still exists (the ScoutSource contract, and the normalize
     * fixtures depend on it), but RegionIngest calls THIS: each page is normalized,
     * written and freed before the next is fetched, so peak memory is a page rather
     * than a city (PagedScoutSource).
     *
     * The old loop was worse than merely buffering. `$objects = [...$objects, ...$page]`
     * REALLOCATES the whole accumulated array on every one of up to 500 iterations —
     * quadratic copying to build a 10,000-POI array we then handed on to be copied four
     * more times. Paris is 4,285 POIs, and this is a large part of why the container died.
     *
     * @return iterable<int, list<array<string, mixed>>>
     */
    public function pages(ScoutRequest $request): iterable
    {
        // geo_bounding is top-left then bottom-right: (north,west),(south,east).
        $url = self::BASE.'?'.http_build_query([
            'geo_bounding' => sprintf(
                '%F,%F,%F,%F',
                $request->north, $request->west, $request->south, $request->east,
            ),
        ]);

        for ($page = 0; $page < self::MAX_PAGES && $url !== null; $page++) {
            // Was retry(3, 5000): a FIXED five-second delay, no jitter, and no regard
            // for Retry-After. Harvest is the ingest lane's shared policy (conventions/09).
            $response = $this->harvest->get(
                $url,
                headers: [
                    'X-API-Key' => (string) $this->key(),
                    'User-Agent' => 'TravelCompanion-ingest/1.0 (rockstoneaidev@gmail.com)',
                ],
                timeout: 60,
            )->throwIfUnknown('datatourisme search');

            yield $response->json('objects') ?? [];

            // The API paginates with an opaque cursor, not an offset.
            $url = $response->json('meta.next');
        }

        // A region that still has pages left is a region we ingested PARTIALLY, and a
        // partial ingest that reports success is a lie the whole pipeline then builds
        // on. Fail loudly instead (conventions/09) — after the pages already yielded
        // have been written, which is strictly better than losing them too.
        if ($url !== null) {
            throw new RuntimeException(sprintf(
                'DATAtourisme region "%s" exceeds %d pages — raise MAX_PAGES rather than ship a truncated region.',
                $request->regionKey,
                self::MAX_PAGES,
            ));
        }
    }

    /**
     * The whole region in one array (ScoutSource).
     *
     * Kept for the contract and for the normalize fixtures, and deliberately NOT used
     * by RegionIngest — it is the buffered path, and buffering a region is what this
     * change exists to stop.
     */
    public function search(ScoutRequest $request): array
    {
        $objects = [];

        // The page-overflow guard lives in pages(); it throws there, so it throws here too.
        foreach ($this->pages($request) as $page) {
            $objects = [...$objects, ...$page];
        }

        return $objects;
    }

    public function normalize(array $raw, string $locale): array
    {
        $candidates = [];

        foreach ($raw as $object) {
            $type = DatatourismeTypeMap::map($object['type'] ?? []);

            if ($type === null) {
                continue;   // a hotel or an estate agent — see the map's note
            }

            $uuid = $object['uuid'] ?? null;
            if (! is_string($uuid) || $uuid === '') {
                continue;
            }

            $name = $this->localized($object['label'] ?? null, $locale);
            if ($name === null) {
                continue;
            }

            $geo = $object['isLocatedAt'][0]['geo'] ?? null;
            if (! isset($geo['latitude'], $geo['longitude'])) {
                continue;
            }

            $candidates[] = $this->candidate(
                externalId: $uuid,
                name: $name,
                altNames: array_values(array_filter([$this->localized($object['label'] ?? null, 'en')])),
                lat: (float) $geo['latitude'],
                lng: (float) $geo['longitude'],
                type: $type,
                sourceTags: [
                    'ontology' => $object['type'] ?? [],
                    // The tourism board's own description. Open-licensed, so it is
                    // persistable — and it is exactly the grounded evidence the
                    // curation pipeline drafts a claim FROM (CURATION §3). It is
                    // evidence, never a fact we assert on our own authority.
                    'description' => $this->localized(
                        $object['hasDescription'][0]['description'] ?? null, $locale,
                    ),
                    'published_by' => $object['hasBeenCreatedBy']['legalName'] ?? null,
                    'last_update' => $object['lastUpdate'] ?? null,
                ],
                externalRefs: [],   // DATAtourisme publishes no Wikidata cross-refs
                language: $locale,
            );
        }

        return $candidates;
    }

    public function ttl(): DateInterval
    {
        return new DateInterval('P30D');
    }

    /**
     * DATAtourisme is multilingual: `{"@fr": "Antik Batik", "@en": "..."}`.
     * Fall back to French, then to whatever single value exists — an unlabelled
     * POI is useless, but a POI labelled only in French is exactly what we want.
     *
     * @param  array<string, string>|null  $field
     */
    private function localized(?array $field, string $locale): ?string
    {
        if ($field === null) {
            return null;
        }

        $value = $field["@{$locale}"] ?? $field['@fr'] ?? (is_string(reset($field)) ? reset($field) : null);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function key(): ?string
    {
        $key = config('services.datatourisme.key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
