<?php

declare(strict_types=1);

namespace App\Domain\Places\Contracts;

use App\Domain\Sources\Enums\ScoutRange;
use DateInterval;

/**
 * An internal scout over our own world model (E5 — the M1 scouts). External
 * paid sources implement ScoutSource (conventions/09) instead; both feed the
 * same shared tile cache.
 */
interface TileScout
{
    /** Cache key segment + scout_runs identity. */
    public function key(): string;

    /** Version lives in the cache key: bumping it invalidates for free. */
    public function version(): string;

    /** Payoff gradient (conventions/09): Near sources never scout far tiles. */
    public function range(): ScoutRange;

    public function ttl(): DateInterval;

    /**
     * Candidates for one res-8 tile, from the canonical places table.
     * Pure read — the runner owns caching and locking.
     *
     * @return list<array<string, mixed>>
     */
    public function candidatesForTile(string $h3Index): array;
}
