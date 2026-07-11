<?php

declare(strict_types=1);

namespace App\Domain\Places\Data;

use App\Domain\Places\Enums\PlaceType;
use App\Domain\Places\Enums\PlaceTypeDomain;
use App\Enums\AppealFacet;

/**
 * What Places publishes about a place to the rest of the app (conventions/01 —
 * another module holds a PlaceData, never a Place).
 *
 * GEO-CORE ZONE data only, so it is safe to serve with its ODbL attribution and
 * nothing else attached (ODBL-REVIEW §6): name, geometry, taxonomy, source.
 */
final readonly class PlaceData
{
    /** @param list<AppealFacet> $facets */
    public function __construct(
        public string $id,
        public string $name,
        public Coordinates $coordinates,
        public PlaceType $type,
        public PlaceTypeDomain $typeDomain,
        public array $facets,
        public string $source,
        public ?int $distanceMeters = null,
    ) {}
}
