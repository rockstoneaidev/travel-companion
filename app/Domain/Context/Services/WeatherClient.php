<?php

declare(strict_types=1);

namespace App\Domain\Context\Services;

use App\Domain\Context\Data\WeatherContext;
use App\Domain\Places\Services\CacheKeys;
use App\Domain\Sources\Services\CircuitBreaker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
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

    /** The sky over this tile now — or an empty context, which is a valid answer. */
    public function forTile(string $h3Index, float $lat, float $lng): WeatherContext
    {
        $cached = Cache::get(CacheKeys::weather($h3Index));

        if ($cached !== null) {
            return $this->fromPayload($cached);
        }

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
    public function rainStartsAt(string $h3Index, float $lat, float $lng, CarbonImmutable $at): ?CarbonImmutable
    {
        $key = CacheKeys::weather($h3Index).':hourly';
        $hourly = Cache::get($key);

        if ($hourly === null) {
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
