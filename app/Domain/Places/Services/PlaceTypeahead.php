<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Domain\Places\Contracts\PlaceLookup;
use App\Domain\Places\Data\PlaceData;
use App\Domain\Places\Data\PlaceSuggestionData;
use App\Domain\Places\Queries\SearchGazetteer;

/**
 * The planner / manual-start typeahead, over BOTH backings (PLAN-DRIVEN-INGESTION §3.2).
 *
 * `places_core` first — where we have detail, a specific place is the richest anchor — then the
 * gazetteer, so any town on Earth can still be a starting point even before it is ingested. That
 * second half is the whole point: it is why "Kusmark", which is in no covered region, now
 * resolves to a coordinate you can plan a trip around (which then drives the ingest, E48).
 *
 * A few slots are reserved for the gazetteer so a dense query ("Stockholm" — where the core holds
 * dozens of specific places) can never crowd out the settlement itself, which is usually the
 * thing a planner actually means.
 */
final class PlaceTypeahead
{
    /** How many result slots the gazetteer is guaranteed, when it has matches. */
    private const GAZETTEER_RESERVED = 3;

    public function __construct(
        private readonly PlaceLookup $places,
        private readonly SearchGazetteer $gazetteer,
    ) {}

    /** @return list<PlaceSuggestionData> */
    public function search(string $query, int $limit = 8): array
    {
        $gazetteer = $this->gazetteer->search($query, $limit);

        $coreSlots = max(0, $limit - min(self::GAZETTEER_RESERVED, count($gazetteer)));

        $core = array_map(
            static fn (PlaceData $place): PlaceSuggestionData => new PlaceSuggestionData(
                id: 'place:'.$place->id,
                name: $place->name,
                coordinates: $place->coordinates,
                type: $place->type->value,
            ),
            array_slice($this->places->search($query, $limit), 0, $coreSlots),
        );

        // Core first, then gazetteer settlements; dedup by name so a town we already hold in the
        // core is not offered twice, and cap at the requested limit.
        $seen = [];
        $out = [];

        foreach ([...$core, ...$gazetteer] as $suggestion) {
            $key = mb_strtolower($suggestion->name);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $suggestion;

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
