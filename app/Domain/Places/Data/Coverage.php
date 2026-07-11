<?php

declare(strict_types=1);

namespace App\Domain\Places\Data;

/**
 * A session's scoutable tile set (conventions/12): every res-8 tile in reach,
 * split into the near ring (walking-scale, all sources) and the far set
 * (ScoutRange::Full sources only — the payoff gradient).
 */
final readonly class Coverage
{
    /**
     * @param  list<string>  $nearTiles
     * @param  list<string>  $farTiles
     */
    public function __construct(
        public string $originCell,
        public string $mode,
        public array $nearTiles,
        public array $farTiles,
    ) {}

    /** @return list<string> */
    public function allTiles(): array
    {
        return [...$this->nearTiles, ...$this->farTiles];
    }
}
