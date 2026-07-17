<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
 * One Overpass query per country, run rarely (this is reference data, not a hot path), upserted
 * by `osm_id` so a re-load is idempotent.
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

    /**
     * Fetch and store every settlement in a country. Returns how many rows were written.
     */
    public function load(string $countryCode): int
    {
        $countryCode = strtoupper($countryCode);
        $elements = $this->fetch($countryCode);

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

        foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
            $this->upsert($chunk);
        }

        return count($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetch(string $countryCode): array
    {
        $query = $this->overpassQuery($countryCode);
        $lastError = null;

        foreach (self::ENDPOINTS as $endpoint) {
            try {
                $response = Http::timeout(300)->asForm()->post($endpoint, ['data' => $query]);

                if ($response->successful()) {
                    return $response->json('elements') ?? [];
                }
            } catch (\Throwable $e) {
                $lastError = $e;   // try the next mirror
            }
        }

        throw new RuntimeException("Overpass gazetteer fetch failed for {$countryCode}", previous: $lastError);
    }

    private function overpassQuery(string $countryCode): string
    {
        $types = implode('|', self::SETTLEMENT_TYPES);

        // The country's admin_level=2 area, then every settlement node inside it. `qt` orders by
        // quadtile so nearby rows land together (kinder on the upsert), and a long server-side
        // timeout because this is a whole country and we are patient.
        return <<<OVERPASS
        [out:json][timeout:900];
        area["ISO3166-1"="{$countryCode}"][admin_level=2]->.country;
        (
          node["place"~"^({$types})\$"]["name"](area.country);
        );
        out qt;
        OVERPASS;
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
