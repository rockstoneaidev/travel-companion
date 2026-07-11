<?php

declare(strict_types=1);

namespace App\Domain\Places\Contracts;

use App\Domain\Places\Data\Coordinates;
use App\Domain\Places\Data\PlaceData;

/**
 * The Places module's public read API (conventions/01). Geometry lives here, so
 * geo lookups live here — no other module writes ST_DWithin against
 * `places_core`.
 */
interface PlaceLookup
{
    /**
     * Places within `$meters` of `$origin`, nearest first, each carrying its
     * distance.
     *
     * @return list<PlaceData>
     */
    public function withinRadius(Coordinates $origin, int $meters, int $limit = 200): array;

    /**
     * @param  list<string>  $ids
     * @return array<string, PlaceData> keyed by place id
     */
    public function findMany(array $ids): array;

    /**
     * Best canonical match for a name (trigram similarity ≥ 0.4), optionally
     * biased to a region's cells — the grounding step's search (CURATION §3).
     */
    public function searchByName(string $name, ?string $regionSlug = null): ?PlaceData;
}
