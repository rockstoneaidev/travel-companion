<?php

declare(strict_types=1);

namespace App\Domain\Context\Services;

use App\Domain\Context\Contracts\Routing;
use App\Domain\Sources\Services\CircuitBreaker;
use App\Domain\Trips\Enums\TravelMode;
use App\Domain\Trips\Enums\TravelMode as Mode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Self-hosted OSRM — Stage B without the meter running (E43; PRD §10, DATA-SOURCES §9).
 *
 * The same contract as GoogleRoutes, and deliberately the same SHAPE — same res-9 origin
 * bucketing, same short TTL, same circuit breaker — so switching between them is a config
 * flip and nothing downstream can tell the difference. What changes is the bill: OSRM runs
 * on our own OSM extract on our own box, so a route costs compute we already pay for
 * instead of $0.005 to Google.
 *
 * ## Null means two different things, and the caller must not care
 *
 * OSRM returns `code: "Ok"` with a route, or `code: "NoRoute"` when the points genuinely
 * cannot be connected (an island, a pedestrian mode across a motorway-only stretch). Both
 * come back as null here, and that is correct: the port's contract is "a number or null",
 * and a null keeps the estimator's figure. The *fallback to Google* — the thing that tells
 * "OSRM is down" apart from "there is no route" — lives one layer up, in FallbackRouting,
 * because it is a policy decision (spend money to recover), not a routing fact.
 */
final class OsrmRoutes implements Routing
{
    public const SOURCE = 'osrm';

    private const TTL_SECONDS = 900;

    private const ORIGIN_RESOLUTION = 9;

    public function __construct(
        private readonly CircuitBreaker $breaker,
    ) {}

    public function minutes(float $fromLat, float $fromLng, float $toLat, float $toLng, TravelMode $mode): ?float
    {
        $key = $this->cacheKey($fromLat, $fromLng, $toLat, $toLng, $mode);

        $cached = Cache::get($key);

        if ($cached !== null) {
            return (float) $cached;
        }

        $minutes = $this->breaker->call(
            self::SOURCE,
            function () use ($fromLat, $fromLng, $toLat, $toLng, $mode): ?float {
                $base = rtrim((string) config('routing.osrm.url'), '/');
                $profile = $this->profile($mode);

                // OSRM wants lng,lat;lng,lat in the path.
                $coords = sprintf('%f,%f;%f,%f', $fromLng, $fromLat, $toLng, $toLat);

                $response = Http::timeout((int) config('routing.osrm.timeout_seconds'))
                    ->get("{$base}/route/v1/{$profile}/{$coords}", ['overview' => 'false'])
                    ->throw();

                // A genuine "no route" is not a failure — it is an answer, and the answer is
                // null. Only a transport error (throw, above) trips the breaker.
                if ($response->json('code') !== 'Ok') {
                    return null;
                }

                $seconds = $response->json('routes.0.duration');

                return $seconds === null ? null : (float) $seconds / 60.0;
            },
            fallback: null,
        );

        if ($minutes === null) {
            return null;
        }

        Cache::put($key, $minutes, self::TTL_SECONDS);

        return $minutes;
    }

    private function cacheKey(float $fromLat, float $fromLng, float $toLat, float $toLng, TravelMode $mode): string
    {
        $originCell = DB::selectOne(
            'SELECT h3_lat_lng_to_cell(POINT(?, ?), ?)::text AS cell',
            [$fromLng, $fromLat, self::ORIGIN_RESOLUTION],
        )->cell;

        $destination = sprintf('%.5f,%.5f', $toLat, $toLng);

        // Distinct namespace from Google's `route:` keys — a self-hosted profile can differ
        // from Google's for the same mode, and sharing a key would serve one engine's answer
        // from the other's cache.
        return "osrm:{$originCell}:{$destination}:{$mode->value}";
    }

    private function profile(TravelMode $mode): string
    {
        // OSRM profiles are per-server-build. These are the conventional names; the
        // deployment builds `foot`, `bicycle` and `driving` extracts (SERVER-DEPLOYMENT).
        return match ($mode) {
            Mode::Walk => 'foot',
            Mode::Bike => 'bicycle',
            Mode::Drive => 'driving',
        };
    }
}
