<?php

declare(strict_types=1);

namespace App\Domain\Trips\Services;

use App\Domain\Places\Data\Coordinates;
use App\Domain\Sources\Data\IngestRegion;
use Carbon\CarbonImmutable;

/**
 * A name for a trip nobody named (PRD §6.6 — trips are IMPLICIT).
 *
 * Because trips form around sessions rather than being declared, nobody is ever
 * asked to name one — so every single one read "Untitled trip", which is the least
 * useful string it is possible to put on a list of memories. The journal is meant to
 * be the seed of "your travel memory belongs to you", and a memory called Untitled
 * is not much of one.
 *
 * "Stockholm, July" — where and when, which is how people actually refer to trips.
 * Derived from the anchor, so it needs nothing from the user and is right the moment
 * the trip exists. It stays editable: this is a good guess, not a decision.
 *
 * Null when we cannot say honestly. A trip outside every known region gets no name
 * rather than a wrong one.
 */
final class NameTrip
{
    public function __invoke(?Coordinates $anchor, CarbonImmutable $at): ?string
    {
        if ($anchor === null) {
            return null;
        }

        foreach (IngestRegion::all() as $region) {
            $inside = $anchor->lat >= $region->south && $anchor->lat <= $region->north
                && $anchor->lng >= $region->west && $anchor->lng <= $region->east;

            if ($inside) {
                return $region->name.', '.$at->format('F');
            }
        }

        return null;
    }
}
