<?php

declare(strict_types=1);

namespace App\Domain\Places\Services\Scouts;

use App\Domain\Sources\Enums\ScoutRange;

/** Viewpoints, waterfalls, parks — the things Google doesn't have. Full range. */
final class NatureScout extends DbScout
{
    public function key(): string
    {
        return 'nature';
    }

    public function range(): ScoutRange
    {
        return ScoutRange::Full;
    }

    public function candidatesForTile(string $h3Index): array
    {
        return $this->placesWhere($h3Index, ['nature_landscape']);
    }
}
