<?php

declare(strict_types=1);

namespace App\Domain\Places\Services\Scouts;

use App\Domain\Sources\Enums\ScoutRange;
use Illuminate\Support\Facades\DB;

/**
 * Locally-rare places (PRD §9.5, v1 approximation): a place whose type is
 * scarce within the uniqueness neighborhood (k-ring 1 at res 8, SCORING §3).
 * E7's unusualness sub-score refines this; the scout only surfaces candidates.
 */
final class UnusualnessScout extends DbScout
{
    private const MAX_NEIGHBORHOOD_COUNT = 2;

    public function key(): string
    {
        return 'unusualness';
    }

    public function range(): ScoutRange
    {
        return ScoutRange::Full;
    }

    public function candidatesForTile(string $h3Index): array
    {
        $rareTypes = array_column(DB::select(
            'SELECT p.type FROM places_core p
             WHERE p.h3_index = ANY (SELECT h3_grid_disk(?::h3index, ?)::text)
             GROUP BY p.type
             HAVING count(*) <= ?',
            [$h3Index, (int) config('tiles.uniqueness.k'), self::MAX_NEIGHBORHOOD_COUNT],
        ), 'type');

        if ($rareTypes === []) {
            return [];
        }

        return array_values(array_filter(
            $this->placesWhere($h3Index),
            static fn (array $c): bool => in_array($c['type'], $rareTypes, true),
        ));
    }
}
