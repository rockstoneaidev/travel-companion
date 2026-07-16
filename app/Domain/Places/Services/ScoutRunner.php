<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Domain\Places\Contracts\TileScout;
use App\Domain\Places\Data\Coverage;
use App\Domain\Places\Enums\PlaceTypeDomain;
use App\Domain\Places\Models\ScoutRun;
use App\Domain\Sources\Enums\ScoutRange;
use App\Jobs\Scouts\WarmTileJob;
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
     * Drop every scout's cached answer for these tiles (E48).
     *
     * Called when new places land in a tile — an on-demand region being learned box by
     * box. Without it the scouts keep serving the emptiness they cached before the ingest,
     * for a full day, and the feed stays dark over a town it has already mapped.
     *
     * Invalidate rather than re-warm: `RankSession::plan()` calls `warm()` before it calls
     * `candidates()`, so the next pull refills these tiles from the database anyway. Warming
     * them here would do the same work twice and pay for it in a queue worker instead of a
     * request that actually wanted the answer.
     *
     * @param  list<string>  $tiles
     * @return int tiles × scouts forgotten
     */
    public function forgetTiles(array $tiles): int
    {
        $forgotten = 0;

        foreach ($this->scouts as $scout) {
            foreach ($tiles as $tile) {
                $this->cache->forget($scout->key(), $tile, $scout->version());
                $forgotten++;
            }
        }

        return $forgotten;
    }

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
                'prescouted' => $this->preScout($scout, $coverage),
            ];
        }

        return $summary;
    }

    /**
     * The road ahead (E35): warm the corridor tail on the queue, in progress order.
     *
     * This is the half of corridor scouting nobody is waiting for, and that is exactly
     * what makes it affordable. The synchronous half serves the cards on screen; this
     * half is a bet that the vehicle will keep going, and the bet is cheap because
     * `WarmTileJob` is `ShouldBeUnique` per (tile, scout) — re-aiming the corridor every
     * few hundred metres re-dispatches mostly the same tiles, and mostly they collapse.
     *
     * **Full-range scouts only.** A `Near` source has no business 40 km up the E4: the
     * payoff gradient (conventions/12) says a café ahead of you on a motorway is noise,
     * and pre-scouting noise costs the same as pre-scouting signal.
     */
    private function preScout(TileScout $scout, Coverage $coverage): int
    {
        if ($coverage->pendingTiles === [] || $scout->range() === ScoutRange::Near) {
            return 0;
        }

        foreach ($coverage->pendingTiles as $tile) {
            WarmTileJob::dispatch($scout::class, $tile);
        }

        return count($coverage->pendingTiles);
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
                    // Phase-2 utility types (toilet, charging point, pharmacy, shelter,
                    // transport hub) are ingested into the world model but must not surface
                    // as Phase-1 opportunities: the taxonomy is explicit that no Phase-1 scout
                    // uses the `practical` domain (TAXONOMY §5). They leaked in via generic OSM
                    // ingestion — a session that arrived at Fjäderholmarna with its budget spent
                    // was served three toilets and nothing else, because a toilet is the only
                    // thing with near-zero dwell that fits a near-empty budget. Dropped here, the
                    // one chokepoint every candidate passes through, so they are never a card or
                    // a map pin until Phase 2 gives them their own utility surface.
                    if (($candidate['type_domain'] ?? null) === PlaceTypeDomain::Practical->value) {
                        continue;
                    }

                    $out[] = $candidate;
                }
            }
        }

        return $out;
    }
}
