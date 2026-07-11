<?php

declare(strict_types=1);

namespace App\Domain\Places\Services\Scouts;

use App\Domain\Sources\Enums\ScoutRange;

/**
 * The curated layer — the actual moat (PRD §9.4). Registered from day one so
 * the runner, cache keys, and hit-rate metrics are already wired when E11
 * lands the curated tables; until then every tile is honestly empty.
 */
final class CuratedScout extends DbScout
{
    public function key(): string
    {
        return 'curated';
    }

    public function version(): string
    {
        return 'v0'; // bumps to v1 when E11's curated tables land
    }

    public function range(): ScoutRange
    {
        return ScoutRange::Full;
    }

    public function candidatesForTile(string $h3Index): array
    {
        return [];
    }
}
