<?php

declare(strict_types=1);

namespace App\Domain\Sources\Services;

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

    /** @return array{name: string, locale: string} */
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

        $name = $address['city']
            ?? $address['town']
            ?? $address['village']
            ?? $address['municipality']
            ?? $address['county']
            ?? ($response['name'] ?? null);

        $country = strtolower((string) ($address['country_code'] ?? ''));

        return [
            'name' => is_string($name) && $name !== '' ? $name : $fallback['name'],
            'locale' => self::LOCALES[$country] ?? 'en',
        ];
    }
}
