<?php

declare(strict_types=1);

namespace App\Domain\Places\Data;

/**
 * A session's scoutable tile set (conventions/12): every res-8 tile in reach,
 * split into the near ring (walking-scale, all sources) and the far set
 * (ScoutRange::Full sources only — the payoff gradient).
 *
 * E35 adds a third set, and it is a different KIND of set.
 *
 * `nearTiles` and `farTiles` mean "scout these now — the ranker is waiting".
 * `pendingTiles` means "scout these soon — nobody is waiting": the corridor
 * ahead of a moving vehicle. Stockholm→Göteborg at a 3 km corridor width is
 * thousands of res-8 cells, and warming them inline would put a multi-minute
 * pipeline inside a request that wanted five cards. So the corridor is ordered
 * by progress along the route, the segment you are about to enter is scouted
 * synchronously, and the tail is handed to the queue to be warm before you get
 * there.
 */
final readonly class Coverage
{
    /**
     * @param  list<string>  $nearTiles  walking-scale ring; every source scouts these
     * @param  list<string>  $farTiles  scouted inline, by ScoutRange::Full sources only
     * @param  list<string>  $pendingTiles  the route ahead, in progress order: pre-scouted on the queue
     */
    public function __construct(
        public string $originCell,
        public string $mode,
        public array $nearTiles,
        public array $farTiles,
        public array $pendingTiles = [],
    ) {}

    /**
     * The tiles this rank may READ.
     *
     * Deliberately excludes `pendingTiles`: they are not warm yet, and reading them
     * would make the feed's contents depend on how far a queue worker happened to
     * have got — the same rank, run twice, would return different cards for reasons
     * having nothing to do with the traveller.
     *
     * @return list<string>
     */
    public function allTiles(): array
    {
        return [...$this->nearTiles, ...$this->farTiles];
    }
}
