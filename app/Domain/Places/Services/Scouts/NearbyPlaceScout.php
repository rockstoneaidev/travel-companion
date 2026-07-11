<?php

declare(strict_types=1);

namespace App\Domain\Places\Services\Scouts;

use App\Domain\Sources\Enums\ScoutRange;

/** The generic nearby sweep — food, shops, everything. Near ring only (a café 30 km ahead is noise). */
final class NearbyPlaceScout extends DbScout
{
    public function key(): string
    {
        return 'nearby';
    }

    public function range(): ScoutRange
    {
        return ScoutRange::Near;
    }

    public function candidatesForTile(string $h3Index): array
    {
        return $this->placesWhere($h3Index);
    }
}
