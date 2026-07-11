<?php

declare(strict_types=1);

namespace App\Domain\Places\Services\Scouts;

use App\Domain\Curation\Services\ApprovedCuratedItems;
use App\Domain\Sources\Enums\ScoutRange;

/**
 * The curated layer — the actual moat (PRD §9.4). Serves ONLY approved items
 * through Curation's public read API; the review gate lives there, so an
 * unreviewed draft cannot reach a feed through any path.
 */
final class CuratedScout extends DbScout
{
    public function __construct(
        private readonly ApprovedCuratedItems $items,
    ) {}

    public function key(): string
    {
        return 'curated';
    }

    public function version(): string
    {
        return 'v1'; // E11: the curated tables are live
    }

    public function range(): ScoutRange
    {
        return ScoutRange::Full;
    }

    public function candidatesForTile(string $h3Index): array
    {
        return $this->items->forTile($h3Index);
    }
}
