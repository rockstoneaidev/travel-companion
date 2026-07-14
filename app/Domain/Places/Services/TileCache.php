<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use Closure;
use DateInterval;
use Illuminate\Support\Facades\Cache;

/**
 * The shared scout cache (conventions/12): per-tile, versioned keys, no user
 * ids ever. A lock guards the expensive fill — a bus of tourists hitting a
 * cold tile must trigger one fill, not N.
 */
final class TileCache
{
    /**
     * @param  Closure(): list<array<string, mixed>>  $fill
     * @return array{0: list<array<string, mixed>>, 1: bool} candidates, wasHit
     */
    /**
     * Forget a tile — because a cached EMPTY is a lie the moment we ingest the place.
     *
     * `DbScout`'s TTL is a day, and "there is nothing in this hexagon" caches exactly like
     * any other answer. So when a region is being learned on demand (E48), the scouts read
     * a cache that was filled while the ground was still virgin, and go on believing it for
     * twenty-four hours — while the places land in the table right underneath them.
     *
     * The founder watched it happen: 27 canonical places in Skellefteå, and a pipeline log
     * reading "49 tiles (49 hit, 0 filled), 0 candidates". Every tile a hit. Every hit empty.
     * The feed said "nothing worth interrupting you for" about a town it had just finished
     * mapping.
     *
     * The cache is not wrong to hold emptiness. It is wrong to hold it after we have made
     * it false.
     */
    public function forget(string $scoutKey, string $h3Index, string $version): void
    {
        Cache::forget(CacheKeys::scout($scoutKey, $h3Index, $version));
    }

    public function remember(string $scoutKey, string $h3Index, string $version, DateInterval $ttl, Closure $fill): array
    {
        $key = CacheKeys::scout($scoutKey, $h3Index, $version);

        $cached = Cache::get($key);
        if ($cached !== null) {
            return [$cached, true];
        }

        return Cache::lock("{$key}:lock", 15)->block(10, function () use ($key, $ttl, $fill): array {
            // Re-check under the lock: another request may have filled it.
            $cached = Cache::get($key);
            if ($cached !== null) {
                return [$cached, true];
            }

            $candidates = $fill();
            Cache::put($key, $candidates, $ttl);

            return [$candidates, false];
        });
    }
}
