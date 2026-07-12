<?php

declare(strict_types=1);

namespace App\Domain\Context\Services;

use App\Domain\Context\Data\WeatherContext;
use App\Domain\Places\Services\CacheKeys;
use App\Domain\Sources\Services\CircuitBreaker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Open-Meteo, at the edge (E16, DATA-SOURCES §2-edge, PRD §9.2).
 *
 * Cached PER TILE, never per user (conventions/12 — a user id in a scout key
 * destroys the shared cache and multiplies the bill by your user count). Everyone
 * standing in the same H3 hex is standing under the same sky, so one call serves
 * all of them.
 *
 * Behind a circuit breaker, because this sits on the read path: when Open-Meteo is
 * down, the feed must still arrive without weather rather than every user waiting
 * out the timeout to learn what the last one already learned. A recommendation
 * missing its weather is a recommendation; one that never arrives is not.
 *
 * ---------------------------------------------------------------------------
 *  WHAT LEAVES THE MACHINE — read before adding a parameter
 * ---------------------------------------------------------------------------
 *
 * The coordinates sent to Open-Meteo are the TILE'S CENTROID, computed here, and
 * never the caller's. This used to take `float $lat, float $lng` and RankSession
 * passed the session origin — the user's actual position — so although the cache
 * key was the tile, the request body was the person. The first user in each hex
 * handed their precise location to a third party we have no Art. 28 DPA with, and
 * the tile-shaped cache key made it look as though we had not (PROCESSORS.md §5,
 * ROPA B1/B3).
 *
 * The centroid is derived from the h3 index rather than accepted as an argument
 * precisely so a caller CANNOT pass a real position. Do not add a lat/lng
 * parameter back: a rule the type system enforces is worth more than a comment
 * asking callers to be careful, and this exact mistake has already been made once.
 *
 * A hex at res 8 is roughly 0.7 km², which is all the resolution a weather forecast
 * has anyway — so this costs the product nothing and removes an entire recipient
 * of personal data.
 */
final class WeatherClient
{
    public const SOURCE = 'open-meteo';

    private const ENDPOINT = 'https://api.open-meteo.com/v1/forecast';

    /** "Weather: frequent" (conventions/12 TTL table). Fifteen minutes is frequent. */
    private const TTL_SECONDS = 900;

    private const TIMEOUT_SECONDS = 4;

    /** Same threshold WeatherContext::isWet() uses — one definition of "wet". */
    private const WET_MM = 0.2;

    /**
     * No CostMeter here on purpose: AppServiceProvider meters every outbound HTTP
     * call from the client's ResponseReceived event, so metering here as well
     * double-counts. That listener exists exactly so an edge call cannot be added
     * without showing up on the trace — including by me, apparently.
     */
    public function __construct(private readonly CircuitBreaker $breaker) {}

    /** @var array<string, array{float, float}> memoised per request — a feed asks for the same hex twice */
    private array $centroids = [];

    /**
     * The centre of the hex, from the h3 extension (conventions/12 — H3 lives in
     * Postgres, so this is the same implementation that assigned the index).
     *
     * @return array{float, float} [lat, lng]
     */
    private function centroid(string $h3Index): array
    {
        return $this->centroids[$h3Index] ??= (function () use ($h3Index): array {
            $point = DB::selectOne(
                'SELECT ST_Y(g) AS lat, ST_X(g) AS lng FROM (SELECT h3_cell_to_geometry(?::h3index) AS g) t',
                [$h3Index],
            );

            return [(float) $point->lat, (float) $point->lng];
        })();
    }

    /** The sky over this tile now — or an empty context, which is a valid answer. */
    public function forTile(string $h3Index): WeatherContext
    {
        $cached = Cache::get(CacheKeys::weather($h3Index));

        if ($cached !== null) {
            return $this->fromPayload($cached);
        }

        [$lat, $lng] = $this->centroid($h3Index);

        $payload = $this->breaker->call(
            self::SOURCE,
            function () use ($lat, $lng): ?array {
                $response = Http::timeout(self::TIMEOUT_SECONDS)
                    ->get(self::ENDPOINT, [
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'current' => 'temperature_2m,precipitation,weather_code,cloud_cover',
                    ])
                    ->throw();

                return $response->json('current');
            },
            fallback: null,
        );

        if ($payload === null) {
            return new WeatherContext;   // unknown, and honest about it
        }

        Cache::put(CacheKeys::weather($h3Index), $payload, self::TTL_SECONDS);

        return $this->fromPayload($payload);
    }

    /**
     * When the rain starts, if it starts today (E18, PRD §12.4).
     *
     * The digest's lede — "Stockholm is dry until four" — is a FACTUAL CLAIM, and
     * the LLM is never a source of facts (CLAUDE.md). So the hour comes from an
     * hourly forecast, and when there is no forecast there is no claim: null, and
     * the lede says something else rather than something invented.
     *
     * Cached on the same tile key as `current`, with its own suffix.
     */
    public function rainStartsAt(string $h3Index, CarbonImmutable $at): ?CarbonImmutable
    {
        $key = CacheKeys::weather($h3Index).':hourly';
        $hourly = Cache::get($key);

        if ($hourly === null) {
            [$lat, $lng] = $this->centroid($h3Index);

            $hourly = $this->breaker->call(
                self::SOURCE,
                fn (): ?array => Http::timeout(self::TIMEOUT_SECONDS)
                    ->get(self::ENDPOINT, [
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'hourly' => 'precipitation',
                        'forecast_days' => 1,
                    ])
                    ->throw()
                    ->json('hourly'),
                fallback: null,
            );

            if ($hourly === null) {
                return null;
            }

            Cache::put($key, $hourly, self::TTL_SECONDS);
        }

        $times = $hourly['time'] ?? [];
        $precipitation = $hourly['precipitation'] ?? [];

        foreach ($times as $i => $time) {
            $hour = CarbonImmutable::parse($time, $at->timezone);

            if ($hour->isBefore($at)) {
                continue;   // rain that already fell is not a forecast
            }

            if ((float) ($precipitation[$i] ?? 0.0) >= self::WET_MM) {
                return $hour;
            }
        }

        return null;   // dry for as far as we can see
    }

    /** @param array<string, mixed> $payload */
    private function fromPayload(array $payload): WeatherContext
    {
        return new WeatherContext(
            temperatureC: isset($payload['temperature_2m']) ? (float) $payload['temperature_2m'] : null,
            precipitationMm: isset($payload['precipitation']) ? (float) $payload['precipitation'] : null,
            weatherCode: isset($payload['weather_code']) ? (int) $payload['weather_code'] : null,
            cloudCoverPercent: isset($payload['cloud_cover']) ? (int) $payload['cloud_cover'] : null,
        );
    }
}
