<?php

declare(strict_types=1);

namespace App\Domain\Context\Services;

use App\Cost\Services\CostMeter;
use App\Cost\Services\SpendGuard;
use App\Domain\Context\Contracts\Routing;
use App\Domain\Sources\Services\CircuitBreaker;
use App\Domain\Trips\Enums\TravelMode;
use App\Domain\Trips\Enums\TravelMode as Mode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Google Routes — Stage B, at the edge (PRD §10, conventions/09).
 *
 * EDGE-ONLY, like everything Google: fetched at recommendation time, used in the
 * response, and let go. A route duration must never be written into a row. The
 * estimator's number is ours and is what gets persisted on the trace; this one is
 * an overlay on the way out.
 *
 * Cached per (destination place, origin res-9 tile, mode), exactly as PRD §10
 * specifies. Res-9 (~174 m edge) rather than the canonical res-8 because the
 * whole point of Stage B is precision: bucketing origins into 460 m hexes would
 * throw away the accuracy we are paying for. Two people on the same street corner
 * share the answer; two people half a kilometre apart do not.
 */
final class GoogleRoutes implements Routing
{
    public const SOURCE = 'google-routes';

    private const URL = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    /** Short: traffic and closures move, and a stale route is a wrong promise. */
    private const TTL_SECONDS = 900;

    private const TIMEOUT_SECONDS = 4;

    /** The origin bucket for the cache key (PRD §10). */
    private const ORIGIN_RESOLUTION = 9;

    public function __construct(
        private readonly CircuitBreaker $breaker,
        private readonly CostMeter $cost,
        private readonly SpendGuard $guard,
    ) {}

    public function minutes(float $fromLat, float $fromLng, float $toLat, float $toLng, TravelMode $mode): ?float
    {
        if ((string) config('services.google.maps_key') === '') {
            return null;   // no key is a supported state — the estimator's number stands
        }

        $key = $this->cacheKey($fromLat, $fromLng, $toLat, $toLng, $mode);

        $cached = Cache::get($key);

        if ($cached !== null) {
            // A hit costs nothing and would have cost $0.005 — which is 8× a whole
            // uncached voice generation (COST.md §3). This cache is not an optimisation,
            // it is the single biggest lever in the unit economics, and this line is the
            // only place its value is observable.
            $this->cost->recordApiCacheHit(
                host: 'routes.googleapis.com',
                vendor: 'google_maps',
                resource: 'routes_essentials',
            );

            return (float) $cached;
        }

        /*
         * The cap (COST.md §8). Routing is the most expensive thing a user can trigger,
         * so it is the first thing to go — and going costs almost nothing: the estimator
         * already produced a number, it is the number we PERSIST on the trace anyway
         * (this call is an overlay on the way out), and every downstream consumer already
         * treats a null here as normal because the circuit breaker can return one.
         */
        if ($this->guard->blocked($this->cost->userId())) {
            return null;
        }

        $minutes = $this->breaker->call(
            self::SOURCE,
            function () use ($fromLat, $fromLng, $toLat, $toLng, $mode): ?float {
                $seconds = Http::timeout(self::TIMEOUT_SECONDS)
                    ->withHeaders([
                        'X-Goog-Api-Key' => (string) config('services.google.maps_key'),
                        'X-Goog-FieldMask' => 'routes.duration',   // the duration, and nothing else
                    ])
                    ->post(self::URL, [
                        'origin' => ['location' => ['latLng' => ['latitude' => $fromLat, 'longitude' => $fromLng]]],
                        'destination' => ['location' => ['latLng' => ['latitude' => $toLat, 'longitude' => $toLng]]],
                        'travelMode' => $this->googleMode($mode),
                    ])
                    ->throw()
                    ->json('routes.0.duration');   // e.g. "742s"

                return $seconds === null ? null : ((float) rtrim((string) $seconds, 's')) / 60.0;
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

        // The destination is a fixed point, so it needs no bucketing — round it to
        // ~1 m and be done.
        $destination = sprintf('%.5f,%.5f', $toLat, $toLng);

        return "route:{$originCell}:{$destination}:{$mode->value}";
    }

    private function googleMode(TravelMode $mode): string
    {
        return match ($mode) {
            Mode::Walk => 'WALK',
            Mode::Bike => 'BICYCLE',
            Mode::Drive => 'DRIVE',
        };
    }
}
