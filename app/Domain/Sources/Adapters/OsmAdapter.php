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
use RuntimeException;
use Throwable;

/**
 * OpenStreetMap adapter (DATA-SOURCES §2): the long-tail layer — viewpoints,
 * ruins, fountains, city gates — the things Google doesn't have.
 *
 * Fetch strategy: Overpass bbox query with adaptive subdivision (see search()).
 * DATA-SOURCES §2 sanctions Overpass for the bounded city-scale regions of
 * Phase 1 — Stockholm and the seven France-corridor cities. When a region stops
 * being city-scale (a country, or continuous re-ingest), the documented
 * production path is a Geofabrik extract via osm2pgsql: same normalize(),
 * different search() plumbing.
 */
final class OsmAdapter implements ScoutSource
{
    use BuildsCandidates;

    public const KEY = 'osm';

    public const VERSION = 'v1';

    // The lz4 endpoint of the main instance: the plain endpoint 504s under
    // load and the Kumi mirror stalls; lz4 answers reliably.
    private const OVERPASS_URL = 'https://lz4.overpass-api.de/api/interpreter';

    // 4 quadrants × 4^3 = up to 256 sub-boxes for the worst region. Paris needs
    // one or two splits; the bound exists so a dead endpoint cannot turn into a
    // fork bomb of politeness sleeps.
    private const MAX_SPLIT_DEPTH = 3;

    public function supports(ScoutRequest $request): bool
    {
        return true; // the base layer supports every region
    }

    /**
     * Overpass times out as a function of how much it has to answer, so the
     * answer to a timeout is a smaller question.
     *
     * A fixed 4-quadrant split was enough for one Stockholm-sized region and is
     * NOT enough for the France corridor: Paris alone returns 34k elements, and
     * running seven cities back to back had public Overpass returning 504 on
     * whole quadrants — silently costing Nantes its entire OSM layer. So the
     * split is now adaptive: a quadrant that fails is quartered and retried,
     * down to a depth bound. Ways on a seam appear in both halves — dedupe on
     * type/id.
     *
     * (This is the shape that survives at city scale. At country scale the
     * documented path is still Geofabrik → osm2pgsql — DATA-SOURCES §2.)
     */
    public function search(ScoutRequest $request): array
    {
        $elements = [];
        $failedBoxes = 0;

        foreach ($this->quadrants($request) as $quadrant) {
            $this->collect($quadrant, 0, $elements, $failedBoxes);
        }

        // Something is better than nothing (coverage honesty, conventions/09) —
        // but *nothing* must not be mistaken for "this region has no places".
        if ($elements === [] && $failedBoxes > 0) {
            throw new RuntimeException(
                "Overpass failed for every sub-box of region \"{$request->regionKey}\" ({$failedBoxes} boxes).",
            );
        }

        return array_values($elements);
    }

    /**
     * @param  array<string, array<string, mixed>>  $elements
     */
    private function collect(ScoutRequest $box, int $depth, array &$elements, int &$failedBoxes): void
    {
        if (! app()->runningUnitTests()) {
            sleep(3); // politeness: public Overpass instances rate-limit bursts
        }

        try {
            $response = Http::timeout(180)
                ->retry(2, 10000)
                ->withHeaders(['User-Agent' => 'TravelCompanion-ingest/1.0 (rockstoneaidev@gmail.com)'])
                ->asForm()
                ->post(self::OVERPASS_URL, ['data' => $this->overpassQuery($box)]);

            $response->throw();
        } catch (Throwable $e) {
            if ($depth >= self::MAX_SPLIT_DEPTH) {
                $failedBoxes++;   // give up on this patch, keep the rest of the region

                return;
            }

            foreach ($this->quadrants($box) as $quadrant) {
                $this->collect($quadrant, $depth + 1, $elements, $failedBoxes);
            }

            return;
        }

        foreach ($response->json('elements') ?? [] as $element) {
            $elements[$element['type'].'/'.$element['id']] = $element;
        }
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

    public function normalize(array $raw, string $locale): array
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
                    $tags["name:{$locale}"] ?? '',
                    $tags['name:en'] ?? '',
                    $tags['alt_name'] ?? '',
                    $tags['old_name'] ?? '',
                ]),
                lat: (float) $lat,
                lng: (float) $lng,
                type: $type,
                sourceTags: $tags,
                externalRefs: $externalRefs,
                language: $locale,
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
