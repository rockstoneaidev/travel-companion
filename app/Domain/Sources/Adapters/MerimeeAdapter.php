<?php

declare(strict_types=1);

namespace App\Domain\Sources\Adapters;

use App\Domain\Places\Taxonomy\MerimeeDenominationMap;
use App\Domain\Sources\Adapters\Concerns\BuildsCandidates;
use App\Domain\Sources\Contracts\ScoutSource;
use App\Domain\Sources\Data\ScoutRequest;
use App\Support\Http\Harvest;
use DateInterval;

/**
 * Base Mérimée — every building protected as a Monument Historique
 * (DATA-SOURCES §7).
 *
 * This is the authoritative French heritage layer: 46,714 buildings, from
 * Notre-Dame to the dolmen in a field. It is the source where "the tiny chapel
 * with the rare fresco" actually lives, and Google does not have it.
 *
 * Served by the Ministry of Culture through Opendatasoft's Explore API — public,
 * no key, Licence Ouverte (Etalab), so it is persistable open core and takes
 * part in entity resolution (ODBL-REVIEW §6).
 *
 * Tier A: a national registry is as authoritative as evidence of existence gets
 * (DATA-SOURCES §1.2), so it can establish a place on its own.
 */
final class MerimeeAdapter implements ScoutSource
{
    use BuildsCandidates;

    public const KEY = 'merimee';

    public const VERSION = 'v1';

    public function __construct(private readonly Harvest $harvest) {}

    private const DATASET = 'liste-des-immeubles-proteges-au-titre-des-monuments-historiques';

    private const BASE = 'https://data.culture.gouv.fr/api/explore/v2.1/catalog/datasets/'.self::DATASET.'/records';

    /** Opendatasoft caps a page at 100. */
    private const PAGE = 100;

    private const MAX_PAGES = 60;   // 6,000 monuments per region is far past any city

    public function supports(ScoutRequest $request): bool
    {
        // A French national registry has nothing to say about Stockholm. Saying
        // so is cheaper — and more honest — than an empty round trip.
        return $request->locale === 'fr';
    }

    public function search(ScoutRequest $request): array
    {
        $records = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            // Was retry(3, 5000) — fixed delay, no jitter, no Retry-After. See Harvest.
            $response = $this->harvest->get(
                self::BASE,
                [
                    'where' => sprintf(
                        'in_bbox(coordonnees_au_format_wgs84, %F, %F, %F, %F)',
                        $request->south, $request->west, $request->north, $request->east,
                    ),
                    'limit' => self::PAGE,
                    'offset' => $page * self::PAGE,
                ],
                ['User-Agent' => 'TravelCompanion-ingest/1.0 (rockstoneaidev@gmail.com)'],
                timeout: 60,
            )->throwIfUnknown('merimee search');

            $batch = $response->json('results') ?? [];
            $records = [...$records, ...$batch];

            if (count($batch) < self::PAGE) {
                break;
            }
        }

        return $records;
    }

    public function normalize(array $raw, string $locale): array
    {
        $candidates = [];

        foreach ($raw as $record) {
            $type = MerimeeDenominationMap::map($record['denomination_de_l_edifice'] ?? null);

            if ($type === null) {
                continue;   // protected, but not an opportunity — see the map's note
            }

            $point = $record['coordonnees_au_format_wgs84'] ?? null;
            if (! is_array($point) || ! isset($point['lat'], $point['lon'])) {
                continue;
            }

            $reference = $record['reference'] ?? null;
            if (! is_string($reference) || $reference === '') {
                continue;   // no stable id ⇒ nothing entity resolution can key on
            }

            $name = $this->name($record);
            if ($name === null) {
                continue;
            }

            $candidates[] = $this->candidate(
                externalId: $reference,
                name: $name,
                altNames: array_values(array_filter([
                    $record['autre_appellation_de_l_edifice'] ?? null,
                    $record['denomination_de_l_edifice'] ?? null,
                ])),
                lat: (float) $point['lat'],
                lng: (float) $point['lon'],
                type: $type,
                sourceTags: [
                    'denomination' => $record['denomination_de_l_edifice'] ?? null,
                    'datation' => $record['datation_de_l_edifice'] ?? null,
                    'protection' => $record['date_et_typologie_de_la_protection'] ?? null,
                    'auteur' => $record['auteur_de_l_edifice'] ?? null,
                    'commune' => $record['commune_forme_editoriale'] ?? $record['commune_forme_index'] ?? null,
                ],
                externalRefs: [],   // Mérimée publishes no Wikidata/Wikipedia cross-refs
                language: $locale,
            );
        }

        return $candidates;
    }

    public function ttl(): DateInterval
    {
        // A monument protected in 1862 is not going to move. The registry itself
        // changes a few times a year.
        return new DateInterval('P90D');
    }

    /** @param array<string, mixed> $record */
    private function name(array $record): ?string
    {
        // `titre_editorial_de_la_notice` is the human title ("Lycée Jules Ferry").
        // The denomination alone ("lycée") is a category, not a name — using it
        // would fill the feed with a hundred places called "église".
        $title = $record['titre_editorial_de_la_notice'] ?? null;

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }
}
