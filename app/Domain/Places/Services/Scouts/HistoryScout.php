<?php

declare(strict_types=1);

namespace App\Domain\Places\Services\Scouts;

use App\Domain\Sources\Enums\ScoutRange;

/** Heritage and history — worth the drive (conventions/09 payoff gradient). */
final class HistoryScout extends DbScout
{
    public function key(): string
    {
        return 'history';
    }

    public function range(): ScoutRange
    {
        return ScoutRange::Full;
    }

    public function candidatesForTile(string $h3Index): array
    {
        return $this->placesWhere($h3Index, ['historic_heritage', 'religious_sacred', 'museum_gallery']);
    }
}
