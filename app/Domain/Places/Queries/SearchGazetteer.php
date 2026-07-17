<?php

declare(strict_types=1);

namespace App\Domain\Places\Queries;

use App\Domain\Places\Data\Coordinates;
use App\Domain\Places\Data\PlaceSuggestionData;
use App\Domain\Places\Models\GazetteerPlace;

/**
 * Search the gazetteer for a settlement to anchor a trip on (PLAN-DRIVEN-INGESTION §3.1).
 *
 * Prefix matches first (typing "kusm" wants Kusmark), then bigger settlements before smaller
 * ones of the same name (a city named X above a hamlet named X), then closest fuzzy match for
 * typos. Same trigram machinery as the places_core typeahead.
 */
final class SearchGazetteer
{
    /** @return list<PlaceSuggestionData> */
    public function search(string $query, int $limit = 8): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        return GazetteerPlace::query()
            ->select('gazetteer_places.*')
            ->selectRaw('ST_Y(location::geometry) as lat')
            ->selectRaw('ST_X(location::geometry) as lng')
            ->where(function ($builder) use ($query): void {
                $builder
                    ->whereRaw('name ILIKE ?', [$query.'%'])
                    ->orWhereRaw('similarity(name, ?) >= 0.3', [$query]);
            })
            ->orderByRaw('(name ILIKE ?) DESC', [$query.'%'])       // starts-with first
            ->orderByRaw("CASE place_type
                WHEN 'city' THEN 6 WHEN 'town' THEN 5 WHEN 'village' THEN 4
                WHEN 'suburb' THEN 3 WHEN 'hamlet' THEN 2 ELSE 1 END DESC")   // bigger first
            ->orderByRaw('COALESCE(population, 0) DESC')
            ->orderByRaw('similarity(name, ?) DESC', [$query])      // then closest
            ->orderBy('id')                                         // stable tiebreak (conventions/07)
            ->limit($limit)
            ->get()
            ->map(fn (GazetteerPlace $place): PlaceSuggestionData => new PlaceSuggestionData(
                id: 'gaz:'.$place->osm_id,
                name: $place->name,
                coordinates: new Coordinates(
                    (float) $place->getAttribute('lat'),
                    (float) $place->getAttribute('lng'),
                ),
                type: $place->place_type,
            ))
            ->all();
    }
}
