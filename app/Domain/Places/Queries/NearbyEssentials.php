<?php

declare(strict_types=1);

namespace App\Domain\Places\Queries;

use App\Domain\Places\Data\Coordinates;
use App\Domain\Places\Enums\PlaceTypeDomain;
use App\Domain\Places\Models\Place;

/**
 * The nearest practical amenities — toilets, pharmacies, chargers, shelter, transport — by
 * pure distance.
 *
 * Deliberately taste-blind and budget-blind: a toilet emergency does not care what you like or
 * how much of your day is notionally left. This is the exact opposite of the discovery feed,
 * which excludes the `practical` domain on purpose (it is not an "opportunity"). Practical
 * places do not belong in what-is-wonderful-around-you — but they are the single most important
 * thing to find when you need one, so they get their own surface, reachable on demand.
 */
final class NearbyEssentials
{
    /**
     * @return list<array{name: string, type: ?string, distance_m: int, lat: float, lng: float}>
     */
    public function near(Coordinates $origin, int $meters = 2000, int $perType = 3): array
    {
        $point = sprintf("ST_GeogFromText('SRID=4326;%s')", $origin->toWkt());

        $rows = Place::query()
            ->select('places_core.name', 'places_core.type')
            ->selectRaw("ST_Distance(location, {$point}) as distance_m")
            ->selectRaw('ST_Y(location::geometry) as lat')
            ->selectRaw('ST_X(location::geometry) as lng')
            ->where('type_domain', PlaceTypeDomain::Practical->value)
            ->whereRaw("ST_DWithin(location, {$point}, ?)", [$meters])
            ->orderBy('distance_m')
            ->orderBy('id')                       // stable tiebreak (conventions/07)
            ->limit(60)
            ->get();

        // Keep only the nearest few of each kind, so a dense cluster of one type (toilets are
        // the commonest) cannot crowd the pharmacy or the shelter off the list.
        $seen = [];
        $out = [];

        foreach ($rows as $row) {
            // `type` is cast to the PlaceType enum on the model; the string value is what the
            // client and the per-type cap want.
            $type = $row->type?->value ?? '';
            $seen[$type] = ($seen[$type] ?? 0) + 1;

            if ($seen[$type] > $perType) {
                continue;
            }

            $out[] = [
                'name' => (string) $row->name,
                'type' => $type,
                'distance_m' => (int) round((float) $row->distance_m),
                'lat' => (float) $row->lat,
                'lng' => (float) $row->lng,
            ];
        }

        // The per-type cap reorders; restore a single nearest-first list — in an emergency the
        // top of the list is what matters.
        usort($out, static fn (array $a, array $b): int => $a['distance_m'] <=> $b['distance_m']);

        return $out;
    }
}
