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
