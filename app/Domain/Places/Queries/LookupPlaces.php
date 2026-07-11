<?php

declare(strict_types=1);

namespace App\Domain\Places\Queries;

use App\Domain\Places\Contracts\PlaceLookup;
use App\Domain\Places\Data\Coordinates;
use App\Domain\Places\Data\PlaceData;
use App\Domain\Places\Models\Place;
use App\Enums\AppealFacet;
use RuntimeException;

final class LookupPlaces implements PlaceLookup
{
    /** @return list<PlaceData> */
    public function withinRadius(Coordinates $origin, int $meters, int $limit = 200): array
    {
        $point = sprintf("ST_GeogFromText('SRID=4326;%s')", $origin->toWkt());

        return Place::query()
            ->select('places_core.*')
            ->selectRaw("ST_Distance(location, {$point}) as distance_meters")
            ->whereRaw("ST_DWithin(location, {$point}, ?)", [$meters])
            ->orderBy('distance_meters')
            ->orderBy('id')                                  // stable tiebreak (conventions/07)
            ->limit($limit)
            ->get()
            ->map(fn (Place $place): PlaceData => self::toData($place, (int) round((float) $place->getAttribute('distance_meters'))))
            ->all();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, PlaceData>
     */
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Place::query()
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (Place $place): array => [$place->id => self::toData($place)])
            ->all();
    }

    private static function toData(Place $place, ?int $distanceMeters = null): PlaceData
    {
        /** @var list<AppealFacet> $facets */
        $facets = $place->facets->values()->all();

        // places_core.location is NOT NULL — a place we cannot locate is a
        // data-integrity failure, not a nullable field.
        $coordinates = Coordinates::fromEwkbHex($place->getRawOriginal('location'))
            ?? throw new RuntimeException("Place {$place->id} has no readable location.");

        return new PlaceData(
            id: $place->id,
            name: $place->name,
            coordinates: $coordinates,
            type: $place->type,
            typeDomain: $place->type_domain,
            facets: $facets,
            source: $place->source,
            distanceMeters: $distanceMeters,
        );
    }
}
