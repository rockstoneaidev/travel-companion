<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Domain\Places\Contracts\TileScout;
use App\Domain\Places\Data\Coverage;
use App\Domain\Places\Models\ScoutRun;
use App\Domain\Sources\Enums\ScoutRange;
use Illuminate\Support\Facades\Cache;

/**
 * Warms the shared tile cache for a session's coverage (E5): each scout gets
 * the tiles its ScoutRange earns (the payoff gradient — Near sources never
 * scout far corridor tiles), and every run records its hit rate. Hit rate is
 * a product metric (conventions/12): it says whether the shared-tile
 * principle is actually paying.
 */
final class ScoutRunner
{
    /** @param iterable<TileScout> $scouts */
    public function __construct(
        private readonly TileCache $cache,
        private readonly iterable $scouts,
    ) {}

    /**
     * @return list<array{scout: string, tiles: int, hits: int, filled: int, candidates: int, hit_rate: float}>
     */
    public function warm(Coverage $coverage, string $trigger = 'session'): array
    {
        $summary = [];

        foreach ($this->scouts as $scout) {
            $tiles = $scout->range() === ScoutRange::Near ? $coverage->nearTiles : $coverage->allTiles();
            $started = hrtime(true);
            $hits = 0;
            $filled = 0;
            $candidates = 0;

            foreach ($tiles as $tile) {
                [$tileCandidates, $wasHit] = $this->cache->remember(
                    $scout->key(), $tile, $scout->version(), $scout->ttl(),
                    fn (): array => $scout->candidatesForTile($tile),
                );

                $wasHit ? $hits++ : $filled++;
                $candidates += count($tileCandidates);
            }

            $run = ScoutRun::query()->create([
                'scout' => $scout->key(),
                'scout_version' => $scout->version(),
                'tiles_requested' => count($tiles),
                'tiles_hit' => $hits,
                'tiles_filled' => $filled,
                'candidates' => $candidates,
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'trigger' => $trigger,
            ]);

            $summary[] = [
                'scout' => $scout->key(),
                'tiles' => count($tiles),
                'hits' => $hits,
                'filled' => $filled,
                'candidates' => $candidates,
                'hit_rate' => $run->hitRate(),
            ];
        }

        return $summary;
    }

    /**
     * Cached candidates for a set of tiles across all scouts — what the
     * ranking pipeline (E7) reads.
     *
     * Cache-only: a cold tile returns empty here and is never filled from this
     * method. Filling happens either ahead of time (BuildRegionWorldModelJob's
     * `warm` phase dispatches a WarmTileJob per tile/scout) or, for a tile the
     * region build has not reached, by warm() on the ranking path.
     *
     * @param  list<string>  $tiles
     * @return list<array<string, mixed>>
     */
    public function candidates(array $tiles, ?ScoutRange $range = null): array
    {
        $out = [];

        foreach ($this->scouts as $scout) {
            if ($range !== null && $scout->range() !== $range) {
                continue;
            }

            foreach ($tiles as $tile) {
                $cached = Cache::get(
                    CacheKeys::scout($scout->key(), $tile, $scout->version()),
                );

                foreach ($cached ?? [] as $candidate) {
                    $out[] = $candidate;
                }
            }
        }

        return $out;
    }
}
