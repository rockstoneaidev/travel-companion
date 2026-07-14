<?php

declare(strict_types=1);

namespace App\Domain\Sources\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * "What is this place called, and what language is it in?" (E48.)
 *
 * Nominatim, and deliberately **not** Google. A region row is a world-model table, and
 * Google Places data may never reach one — that is simultaneously a Google ToS rule and
 * an ODbL one (ODBL-REVIEW §6, conventions/09). A bounding box or a city name derived
 * from Google, written into `derived_regions`, would poison the ODbL Derivative Database
 * exactly as a cached opening-hour would. It is not a nit; it is a licensing incident.
 *
 * Nominatim is OSM — the same licence as everything else in the geo-core — free, and
 * requires only politeness: a real User-Agent and ≤1 request/second. We call it once per
 * region ever derived, so that budget is not a constraint.
 *
 * The LOCALE is the reason this exists at all, more than the name. The source adapters
 * read `name:{locale}` and follow the matching Wikipedia sitelink; every one of them was
 * hard-coded to Swedish until the France corridor forced the fix (E13). A region derived
 * in Portugal that inherits `sv` is a region that quietly ingests nothing.
 */
final class ReverseGeocoder
{
    private const URL = 'https://nominatim.openstreetmap.org/reverse';

    private const TIMEOUT_SECONDS = 6;

    /** Country → the locale its OSM `name:{locale}` tags actually use. */
    private const LOCALES = [
        'se' => 'sv', 'no' => 'no', 'dk' => 'da', 'fi' => 'fi', 'is' => 'is',
        'fr' => 'fr', 'be' => 'fr', 'de' => 'de', 'at' => 'de', 'ch' => 'de',
        'es' => 'es', 'pt' => 'pt', 'it' => 'it', 'nl' => 'nl',
        'pl' => 'pl', 'cz' => 'cs', 'gr' => 'el',
    ];

    /**
     * @return array{name: string, locale: string}
     *
     * TAKES A TILE, NOT A PERSON — and that is a GDPR requirement, not a style choice.
     *
     * The first version of this took the session origin and posted it straight to
     * Nominatim: a real user's exact coordinate, leaving the system to a third party, to
     * answer a question ("what is this town called?") that does not need it. ROPA §6 said
     * of the OSM family "region bounding boxes — no user data at all", and I quietly made
     * that false. It is the same mistake as open finding B3, where Open-Meteo receives raw
     * coordinates instead of a tile centroid.
     *
     * A res-8 cell is ~0.74 km². Naming a city from its centroid gives the same answer as
     * naming it from a doorstep, and the doorstep is the part that identifies somebody.
     */
    public function forTile(string $h3Index): array
    {
        [$lat, $lng] = $this->centroid($h3Index);

        return $this->describe($lat, $lng);
    }

    /**
     * @return array{name: string, locale: string}
     *
     * @internal Coarsen first — callers should reach for {@see forTile()}.
     */
    public function describe(float $lat, float $lng): array
    {
        $fallback = ['name' => sprintf('%.3f, %.3f', $lat, $lng), 'locale' => 'en'];

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                // Nominatim's usage policy asks for a real identity. An anonymous client
                // is a client they are entitled to block, and we would deserve it.
                ->withHeaders(['User-Agent' => 'TravelCompanion/1.0 (+'.config('privacy.controller_email').')'])
                ->get(self::URL, [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'jsonv2',
                    // City-scale, not house-number scale: we are naming a region.
                    'zoom' => 10,
                ])
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            // A region with an ugly name is worth having. A region we refused to learn
            // because a geocoder was down is not.
            Log::warning('Reverse geocode failed; naming the region after its coordinates.', [
                'lat' => $lat, 'lng' => $lng, 'error' => $e->getMessage(),
            ]);

            return $fallback;
        }

        $address = is_array($response['address'] ?? null) ? $response['address'] : [];

        /*
         * Prefer the SETTLEMENT. Fall back to the administrative unit, and strip its
         * suffix when we do.
         *
         * Nominatim answers `city`/`town`/`village` when the point is in one and falls back
         * to `municipality` out in the countryside — so two cells around the same town came
         * back as "Skellefteå" and "Skellefteå kommun", and the console listed them as
         * though they were different places.
         *
         * They are not. A `kommun` is an administrative wrapper around a town, and the town
         * is the thing a person recognises. Stripping the suffix is not cosmetic tidying:
         * it is the difference between a region list an operator can read and one they have
         * to decode.
         *
         * (The KEY does not care — identity is the H3 cell. Two neighbouring cells may
         * quite properly both be labelled "Skellefteå"; they are different ground with the
         * same nearest town, and the key says so.)
         */
        $name = $address['city']
            ?? $address['town']
            ?? $address['village']
            ?? $this->settlement($address['municipality'] ?? null)
            ?? $this->settlement($address['county'] ?? null)
            ?? ($response['name'] ?? null);

        $country = strtolower((string) ($address['country_code'] ?? ''));

        return [
            'name' => is_string($name) && $name !== '' ? $name : $fallback['name'],
            'locale' => self::LOCALES[$country] ?? 'en',
        ];
    }

    /** @return array{0: float, 1: float} */
    private function centroid(string $h3Index): array
    {
        $row = DB::selectOne(
            'SELECT ST_Y(g) AS lat, ST_X(g) AS lng FROM (SELECT h3_cell_to_geometry(?::h3index) AS g) t',
            [$h3Index],
        );

        return [(float) $row->lat, (float) $row->lng];
    }

    /**
     * "Skellefteå kommun" → "Skellefteå". "Comté de X" → "X".
     *
     * Only the administrative wrapper, and only when it is a suffix or prefix of something
     * else — never enough of the string that removing it leaves nothing to call the place.
     */
    private function settlement(?string $administrative): ?string
    {
        if ($administrative === null || $administrative === '') {
            return null;
        }

        $stripped = preg_replace(
            '/\s+(kommun|kommune|municipality|county|district|department|province|region)$/iu',
            '',
            $administrative,
        );

        $stripped = preg_replace(
            '/^(municipality|commune|arrondissement|département|departement)\s+(of|de|du|des|d\')\s*/iu',
            '',
            (string) $stripped,
        );

        $stripped = trim((string) $stripped);

        return $stripped === '' ? $administrative : $stripped;
    }
}
