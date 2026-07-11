<?php

declare(strict_types=1);

namespace App\Domain\Places\Contracts;

/**
 * The canonical H3 res-8 tile grid (conventions/12): the scout/cache unit.
 * Cross-module boundary — Sources buckets candidates into tiles through this
 * contract, never through its own geo math (conventions/01).
 */
interface TileIndexer
{
    /** Res-8 H3 cell for a WGS84 point, e.g. "8808866189fffff". */
    public function cellFor(float $lat, float $lng): string;

    /**
     * Bulk variant for ingest: list<[lat, lng]> → list<string>, same order.
     *
     * @param  list<array{0: float, 1: float}>  $points
     * @return list<string>
     */
    public function cellsFor(array $points): array;
}
