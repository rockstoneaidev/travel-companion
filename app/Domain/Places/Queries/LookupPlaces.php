<?php

declare(strict_types=1);

namespace App\Domain\Places\Queries;

use App\Domain\Places\Contracts\PlaceLookup;
use App\Domain\Places\Data\Coordinates;
use App\Domain\Places\Data\PlaceData;
use App\Domain\Places\Models\Place;
use App\Domain\Places\Models\PlaceMerge;
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
     * Redirects resolve here (ENTITY-RESOLUTION §2): a merged-away id returns
     * its canonical place, keyed by the id the caller asked for — stale
     * references in user data keep working.
     *
     * @param  list<string>  $ids
     * @return array<string, PlaceData>
     */
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $redirects = PlaceMerge::query()
            ->whereIn('old_place_id', $ids)
            ->pluck('canonical_place_id', 'old_place_id')
            ->all();

        $canonicalIds = array_values(array_unique(array_map(
            static fn (string $id): string => $redirects[$id] ?? $id,
            $ids,
        )));

        $places = Place::query()
            ->whereIn('id', $canonicalIds)
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($ids as $id) {
            $place = $places->get($redirects[$id] ?? $id);
            if ($place !== null) {
                $out[$id] = self::toData($place);
            }
        }

        return $out;
    }

    public function searchByName(string $name, ?string $regionSlug = null): ?PlaceData
    {
        $place = Place::query()
            ->whereRaw('similarity(name, ?) >= 0.4', [$name])
            ->orderByRaw('similarity(name, ?) DESC', [$name])
            ->orderBy('id')
            ->first();

        return $place === null ? null : self::toData($place);
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
