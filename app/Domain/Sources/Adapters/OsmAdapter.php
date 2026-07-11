<?php

declare(strict_types=1);

namespace App\Domain\Sources\Adapters;

use App\Domain\Places\Enums\PlaceTypeDomain;
use App\Domain\Places\Taxonomy\OsmTagMap;
use App\Domain\Sources\Adapters\Concerns\BuildsCandidates;
use App\Domain\Sources\Contracts\ScoutSource;
use App\Domain\Sources\Data\ScoutRequest;
use DateInterval;
use Illuminate\Support\Facades\Http;

/**
 * OpenStreetMap adapter (DATA-SOURCES §2): the long-tail layer — viewpoints,
 * ruins, fountains, city gates — the things Google doesn't have.
 *
 * Fetch strategy: Overpass bbox query, which DATA-SOURCES sanctions for
 * dev-scale work and which comfortably covers the small bounded test regions
 * of Phase 1. When a region outgrows it (country-scale ingest), the documented
 * production path is a Geofabrik extract via osm2pgsql — same normalize(),
 * different search() plumbing. Revisit at E13 (France corridor).
 */
final class OsmAdapter implements ScoutSource
{
    use BuildsCandidates;

    public const KEY = 'osm';

    public const VERSION = 'v1';

    // The lz4 endpoint of the main instance: the plain endpoint 504s under
    // load and the Kumi mirror stalls; lz4 answers reliably.
    private const OVERPASS_URL = 'https://lz4.overpass-api.de/api/interpreter';

    public function supports(ScoutRequest $request): bool
    {
        return true; // the base layer supports every region
    }

    public function search(ScoutRequest $request): array
    {
        // A whole region in one Overpass call routinely times out; four
        // quadrant calls stay small, and each is independently retryable.
        // Ways on a quadrant seam appear twice — dedupe on type/id.
        $elements = [];

        foreach ($this->quadrants($request) as $i => $quadrant) {
            if ($i > 0 && ! app()->runningUnitTests()) {
                sleep(3); // politeness: public Overpass instances rate-limit bursts
            }

            $response = Http::timeout(180)
                ->retry(3, 10000)
                ->withHeaders(['User-Agent' => 'TravelCompanion-ingest/1.0 (rockstoneaidev@gmail.com)'])
                ->asForm()
                ->post(self::OVERPASS_URL, ['data' => $this->overpassQuery($quadrant)]);

            $response->throw();

            foreach ($response->json('elements') ?? [] as $element) {
                $elements[$element['type'].'/'.$element['id']] = $element;
            }
        }

        return array_values($elements);
    }

    /** @return list<ScoutRequest> */
    private function quadrants(ScoutRequest $request): array
    {
        $midLat = ($request->south + $request->north) / 2;
        $midLng = ($request->west + $request->east) / 2;

        $box = static fn (float $s, float $w, float $n, float $e): ScoutRequest => new ScoutRequest(
            regionKey: $request->regionKey, south: $s, west: $w, north: $n, east: $e, locale: $request->locale,
        );

        return [
            $box($request->south, $request->west, $midLat, $midLng),
            $box($request->south, $midLng, $midLat, $request->east),
            $box($midLat, $request->west, $request->north, $midLng),
            $box($midLat, $midLng, $request->north, $request->east),
        ];
    }

    public function normalize(array $raw): array
    {
        $candidates = [];

        foreach ($raw as $element) {
            $tags = $element['tags'] ?? [];
            $type = OsmTagMap::map($tags);

            if ($type === null) {
                continue;
            }

            // Unnamed elements are only useful as practical infrastructure
            // (a toilet needs no name; an unnamed "castle" is tag noise).
            $name = $tags['name'] ?? null;
            if ($name === null && $type->domain() !== PlaceTypeDomain::Practical) {
                continue;
            }

            $lat = $element['lat'] ?? $element['center']['lat'] ?? null;
            $lng = $element['lon'] ?? $element['center']['lon'] ?? null;
            if ($lat === null || $lng === null) {
                continue;
            }

            $externalRefs = array_filter([
                'wikidata' => $tags['wikidata'] ?? null,
                'wikipedia' => $tags['wikipedia'] ?? null,
            ]);

            $candidates[] = $this->candidate(
                externalId: $element['type'].'/'.$element['id'],
                name: $name ?? $type->value,
                altNames: array_filter([
                    $tags['name:sv'] ?? '',
                    $tags['name:en'] ?? '',
                    $tags['alt_name'] ?? '',
                    $tags['old_name'] ?? '',
                ]),
                lat: (float) $lat,
                lng: (float) $lng,
                type: $type,
                sourceTags: $tags,
                externalRefs: $externalRefs,
                language: 'sv',
            );
        }

        return $candidates;
    }

    public function ttl(): DateInterval
    {
        return new DateInterval('P30D'); // static places: weeks (PRD §9.3)
    }

    /**
     * One query covering exactly the primary tags OsmTagMap maps — the adapter
     * never fetches what normalize() would discard.
     */
    private function overpassQuery(ScoutRequest $request): string
    {
        $bbox = $request->bboxAsString();

        $selectors = [
            '["historic"]',
            '["tourism"~"^(viewpoint|museum|gallery|artwork)$"]',
            '["natural"~"^(waterfall|beach|cave_entrance|cliff|spring|rock|stone)$"]',
            '["natural"="water"]["water"="lake"]',
            '["craft"~"^(winery|brewery|distillery|pottery|goldsmith|jeweller|leather|shoemaker|watchmaker|glassblower|carpenter|bookbinder)$"]',
            '["amenity"~"^(place_of_worship|restaurant|cafe|marketplace|theatre|concert_hall|cinema|arts_centre|fountain|pharmacy|toilets|charging_station|shelter)$"]',
            '["leisure"~"^(park|garden|beach_resort|sports_centre|stadium|sauna)$"]',
            '["man_made"="tower"]',
            '["shop"~"^(bakery|deli|cheese|books|antiques|chocolate|confectionery|coffee|tea|wine)$"]',
            '["place"="square"]',
        ];

        $body = implode('', array_map(
            static fn (string $selector): string => "nwr{$selector}({$bbox});",
            $selectors,
        ));

        return "[out:json][timeout:120];({$body});out center tags;";
    }
}
