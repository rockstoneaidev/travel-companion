<?php

declare(strict_types=1);

namespace App\Domain\Places\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Entity-resolution decision bands (ENTITY-RESOLUTION.md §3 stages 1+4).
 * False merges are worse than duplicates: the auto band is conservative,
 * the review band wide.
 */
enum MatchBand: string
{
    use HasOptions;

    case Explicit = 'explicit';   // explicit-ID join (OSM wikidata=* etc.) — auto-merge
    case High = 'high';           // match ≥ 0.82 — auto-merge
    case Review = 'review';       // 0.60–0.82 — human review queue; serve separately meanwhile
    case Distinct = 'distinct';   // < 0.60

    public function autoMerges(): bool
    {
        return $this === self::Explicit || $this === self::High;
    }
}
