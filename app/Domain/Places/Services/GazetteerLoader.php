<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Loads a country's settlement names into the gazetteer from OSM (PLAN-DRIVEN-INGESTION §3.1).
 *
 * SETTLEMENT-FOCUSED on purpose: city/town/village/hamlet/suburb/neighbourhood, and NOT the
 * `locality`/`isolated_dwelling` long tail. That distinction is the whole size story — Sweden's
 * OSM data carries ~a million `place=locality` nodes from a cadastral import, and pulling them
 * would turn a tens-of-MB load into most of a gigabyte. The named villages people actually plan
 * trips to (Kusmark among them) are `village`/`hamlet`, and those we keep.
 *
 * TILED and AREA-CLIPPED. A whole-country `area[...]` fetch works for a sparse country like
 * Sweden but 504s for a dense one like France — too big for Overpass in one go. So the country
 * is walked as a grid of ~1.5° tiles, each cheap and upserted before the next. But a tile is a
 * rectangle, and a country is not: France's bounding box overlaps Germany, Switzerland, Italy
 * and Spain, and a bbox-only query pulls their towns in tagged as France (it did — 29k of them).
 * So each tile query intersects the tile with the country's admin AREA — accurate coverage, and
 * still tile-sized, so no 504.
 *
 * NOTE for whoever loads more countries: this runs SYNCHRONOUSLY (one artisan command, no
 * queue), and area-clipping is slow — Overpass re-resolves the country boundary per request, so
 * France is ~30–40 min. A crash or a killed process loses everything after the last upserted
 * tile. That is fine for a rare, one-off reference load run by hand. If it becomes a hot path
 * (many countries, on-demand), make it a RESUMABLE QUEUED JOB that dispatches one tile per job
 * and re-dispatches until done — the exact pattern `App\Jobs\Ingest\BackfillPhotosJob` uses for
 * the photo backfill — so a hiccup resumes instead of restarting.
 */
final class GazetteerLoader
{
    /** Overpass mirrors, same fleet the scout adapter uses. */
    private const ENDPOINTS = [
        'https://lz4.overpass-api.de/api/interpreter',
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
    ];

    private const SETTLEMENT_TYPES = ['city', 'town', 'village', 'hamlet', 'suburb', 'neighbourhood'];

    private const UPSERT_CHUNK = 1000;

    /** ~1.5° a side: small enough that a bbox place-node query is cheap even over a metro. */
    private const CELL_DEGREES = 1.5;

    /**
     * Mainland bounding boxes [south, west, north, east]. A bbox tile can spill a few places
     * across a land border tagged with this country — harmless for a search index (the place
     * exists at those coordinates), and far cheaper than an admin-area intersection per tile.
     */
    private const COUNTRY_BBOX = [
        'SE' => [55.0, 10.5, 69.5, 24.5],
        'FR' => [41.0, -5.5, 51.5, 9.8],
    ];

    /**
     * Fetch and store every settlement in a country. Returns how many distinct rows the country
     * has afterwards (tiles overlap at their edges; the osm_id upsert dedups them).
     */
    public function load(string $countryCode): int
    {
        $countryCode = strtoupper($countryCode);
        $bbox = self::COUNTRY_BBOX[$countryCode]
            ?? throw new RuntimeException("No bounding box configured for country {$countryCode}.");

        foreach ($this->tiles($bbox) as $tile) {
            try {
                $elements = $this->fetchTile($tile, $countryCode);
            } catch (\Throwable $e) {
                // One busy tile must not sink the load — record the gap and keep going.
                Log::warning('gazetteer tile failed', ['country' => $countryCode, 'tile' => $tile, 'error' => $e->getMessage()]);

                continue;
            }

            foreach (array_chunk($this->rows($elements, $countryCode), self::UPSERT_CHUNK) as $chunk) {
                $this->upsert($chunk);
            }
        }

        return DB::table('gazetteer_places')->where('country_code', $countryCode)->count();
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox
     * @return iterable<array{0: float, 1: float, 2: float, 3: float}>
     */
    private function tiles(array $bbox): iterable
    {
        [$south, $west, $north, $east] = $bbox;

        for ($lat = $south; $lat < $north; $lat += self::CELL_DEGREES) {
            for ($lng = $west; $lng < $east; $lng += self::CELL_DEGREES) {
                yield [
                    round($lat, 4),
                    round($lng, 4),
                    round(min($lat + self::CELL_DEGREES, $north), 4),
                    round(min($lng + self::CELL_DEGREES, $east), 4),
                ];
            }
        }
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $tile
     * @return list<array<string, mixed>>
     */
    private function fetchTile(array $tile, string $countryCode): array
    {
        $query = $this->overpassQuery($tile, $countryCode);
        $lastError = null;

        foreach (self::ENDPOINTS as $endpoint) {
            try {
                // Overpass rejects requests without a real User-Agent (406) — the same header
                // the scout adapter sends, or the mirror refuses us.
                $response = Http::timeout(180)
                    ->withHeaders(['User-Agent' => 'TravelCompanion-ingest/1.0 (rockstoneaidev@gmail.com)'])
                    ->asForm()
                    ->post($endpoint, ['data' => $query]);

                if ($response->successful()) {
                    return $response->json('elements') ?? [];
                }

                $lastError = new RuntimeException("Overpass {$endpoint} returned HTTP {$response->status()}");
            } catch (\Throwable $e) {
                $lastError = $e;   // try the next mirror
            }
        }

        throw new RuntimeException('Overpass tile fetch failed', previous: $lastError);
    }

    private function overpassQuery(array $tile, string $countryCode): string
    {
        [$south, $west, $north, $east] = $tile;
        $types = implode('|', self::SETTLEMENT_TYPES);

        // The country's admin area INTERSECTED with the tile: `(area.c)` clips to the country so
        // a bbox straddling a border does not pull the neighbour's towns, and `(bbox)` keeps the
        // result tile-sized so it never 504s. Overpass caches the country area after the first
        // tile, so this costs a lookup, not a recompute, per tile. `qt` orders by quadtile.
        return <<<OVERPASS
        [out:json][timeout:120];
        area["ISO3166-1"="{$countryCode}"][admin_level=2]->.c;
        node["place"~"^({$types})\$"]["name"](area.c)({$south},{$west},{$north},{$east});
        out qt;
        OVERPASS;
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @return list<array<string, mixed>>
     */
    private function rows(array $elements, string $countryCode): array
    {
        $rows = [];

        foreach ($elements as $element) {
            if (($element['type'] ?? null) !== 'node') {
                continue;
            }

            $tags = $element['tags'] ?? [];
            $name = $tags['name'] ?? null;
            $place = $tags['place'] ?? null;

            // A settlement with no name is not a search result; a node with no coordinate is
            // not a place. Overpass gives nodes their lat/lon inline.
            if ($name === null || $place === null || ! isset($element['lat'], $element['lon'])) {
                continue;
            }

            $rows[] = [
                'osm_id' => (int) $element['id'],
                'name' => (string) $name,
                'place_type' => (string) $place,
                'population' => $this->population($tags['population'] ?? null),
                'country_code' => $countryCode,
                'admin_label' => $tags['is_in'] ?? $tags['addr:municipality'] ?? null,
                'lat' => (float) $element['lat'],
                'lng' => (float) $element['lon'],
            ];
        }

        return $rows;
    }

    private function population(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        // OSM population tags come as "35000", "35,000", "1 234" — keep the digits.
        $digits = preg_replace('/\D/', '', (string) $raw);

        return $digits === '' ? null : (int) $digits;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsert(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $placeholders = [];
        $bindings = [];

        foreach ($rows as $row) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, now(), now())';
            array_push(
                $bindings,
                $row['osm_id'], $row['name'], $row['place_type'], $row['population'],
                $row['country_code'], $row['admin_label'], $row['lng'], $row['lat'],
            );
        }

        $values = implode(', ', $placeholders);

        DB::statement(
            "INSERT INTO gazetteer_places
                (osm_id, name, place_type, population, country_code, admin_label, location, created_at, updated_at)
             VALUES {$values}
             ON CONFLICT (osm_id) DO UPDATE SET
                name = EXCLUDED.name,
                place_type = EXCLUDED.place_type,
                population = EXCLUDED.population,
                country_code = EXCLUDED.country_code,
                admin_label = EXCLUDED.admin_label,
                location = EXCLUDED.location,
                updated_at = now()",
            $bindings,
        );
    }
}
