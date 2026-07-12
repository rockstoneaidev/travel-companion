<?php

declare(strict_types=1);

namespace App\Domain\Context\Services;

use App\Domain\Context\Data\OpeningHours;
use App\Domain\Places\Contracts\ExternalIdRegistry;
use App\Domain\Places\Services\CacheKeys;
use App\Domain\Sources\Services\CircuitBreaker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * "Is it actually open?" — verified at the edge, never persisted (E16).
 *
 * ===========================================================================
 *  READ conventions/09 §"Google, specifically" BEFORE TOUCHING THIS FILE.
 * ===========================================================================
 *
 * Google Places data is EDGE-ONLY. Fetch at recommendation time, use it in the
 * response, let it go. The single Google-derived value that may be written to a
 * database is the `place_id` STRING, in `place_source_ids`, as an external
 * identifier — not the name, not the coordinates, not the rating, and above all
 * not these opening hours, however tempting it is to cache them "just for a day".
 *
 * This is simultaneously a Google ToS requirement and an ODbL one: mixing
 * proprietary data into an ODbL Derivative Database poisons it (ODBL-REVIEW §6).
 * Persisting it is not a code-review nit, it is a licensing incident.
 *
 * So: hours live in Redis, under a short TTL, and nowhere else. And the TTL is
 * short for a second reason that is about honesty rather than law —
 * "verified before recommendation" is a real rule (conventions/12): we do not
 * tell someone a place is open on the strength of a day-old cache.
 */
final class GoogleHoursVerifier
{
    public const SOURCE = 'google-places';

    private const DETAILS_URL = 'https://places.googleapis.com/v1/places/';

    private const SEARCH_URL = 'https://places.googleapis.com/v1/places:searchText';

    /** Short, because a stale "open" is worse than no answer at all. */
    private const HOURS_TTL_SECONDS = 600;

    private const TIMEOUT_SECONDS = 4;

    public function __construct(
        private readonly CircuitBreaker $breaker,
        // The concordance table belongs to Places. Context may hold a place_id string,
        // never another module's Eloquent model (conventions/01 — the arch test caught
        // me reaching straight into PlaceSourceId).
        private readonly ExternalIdRegistry $externalIds,
    ) {}

    /**
     * Hours for one of our places — or "unknown", which is a fine answer.
     *
     * Unknown is NOT closed. Most of the OSM long tail has no hours anywhere, and
     * treating silence as "shut" would delete exactly the layer this product exists
     * to surface.
     */
    public function forPlace(string $placeId, string $name, float $lat, float $lng, ?CarbonImmutable $at = null): OpeningHours
    {
        if (! $this->configured()) {
            return new OpeningHours;
        }

        $googleId = $this->googlePlaceId($placeId, $name, $lat, $lng);

        if ($googleId === null) {
            return new OpeningHours;
        }

        $payload = Cache::get(CacheKeys::placeHours($placeId));

        if ($payload === null) {
            $payload = $this->breaker->call(
                self::SOURCE,
                fn (): ?array => Http::timeout(self::TIMEOUT_SECONDS)
                    ->withHeaders([
                        'X-Goog-Api-Key' => (string) config('services.google.maps_key'),
                        'X-Goog-FieldMask' => 'currentOpeningHours.openNow,currentOpeningHours.nextCloseTime',
                    ])
                    ->get(self::DETAILS_URL.$googleId)
                    ->throw()
                    ->json('currentOpeningHours'),
                fallback: null,
            );

            if ($payload === null) {
                return new OpeningHours;
            }

            // Redis, short TTL, and nowhere else. This is the ONLY place these
            // values are allowed to live.
            Cache::put(CacheKeys::placeHours($placeId), $payload, self::HOURS_TTL_SECONDS);
        }

        if (! isset($payload['openNow'])) {
            return new OpeningHours;
        }

        return new OpeningHours(
            known: true,
            openNow: (bool) $payload['openNow'],
            closesAt: isset($payload['nextCloseTime']) ? CarbonImmutable::parse($payload['nextCloseTime']) : null,
        );
    }

    /**
     * Our place → Google's place_id. Resolved once, then stored — because the
     * place_id string is the one Google value we are permitted to keep, and
     * re-searching for it on every feed would be paying twice for an answer that
     * does not change.
     */
    private function googlePlaceId(string $placeId, string $name, float $lat, float $lng): ?string
    {
        $existing = $this->externalIds->externalIdFor($placeId, 'google');

        if ($existing !== null) {
            return $existing;
        }

        $found = $this->breaker->call(
            self::SOURCE,
            fn (): ?string => Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders([
                    'X-Goog-Api-Key' => (string) config('services.google.maps_key'),
                    'X-Goog-FieldMask' => 'places.id',   // the id, and NOTHING else
                ])
                ->post(self::SEARCH_URL, [
                    'textQuery' => $name,
                    'maxResultCount' => 1,
                    'locationBias' => [
                        'circle' => ['center' => ['latitude' => $lat, 'longitude' => $lng], 'radius' => 200.0],
                    ],
                ])
                ->throw()
                ->json('places.0.id'),
            fallback: null,
        );

        if ($found === null) {
            return null;
        }

        /*
         * The place_id string, and NOTHING else — if you are ever tempted to add a
         * field here, re-read the docblock at the top of this file.
         *
         * remember() returns false when another of our places already claims this
         * Google entity: the text search matched something that is not ours to take.
         * We then decline to verify hours at all, because we will not report opening
         * hours from a match we do not trust — and a wrong "open" is worse than no
         * answer.
         */
        if (! $this->externalIds->remember($placeId, 'google', $found)) {
            return null;
        }

        return $found;
    }

    private function configured(): bool
    {
        return (string) config('services.google.maps_key') !== '';
    }
}
