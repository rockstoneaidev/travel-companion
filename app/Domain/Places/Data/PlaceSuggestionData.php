<?php

declare(strict_types=1);

namespace App\Domain\Places\Data;

/**
 * One typeahead suggestion for the planner / manual start point (SCREENS S2). The union shape
 * over both backings — a detailed place from `places_core` and a settlement from the gazetteer —
 * because the anchor only needs a name and a coordinate, whichever it came from.
 *
 * `id` is namespaced by origin (`place:…` / `gaz:…`) so it stays a stable list key without
 * pretending a gazetteer settlement is a `places_core` row.
 */
final readonly class PlaceSuggestionData
{
    public function __construct(
        public string $id,
        public string $name,
        public Coordinates $coordinates,
        public string $type,
    ) {}

    /** @return array{id: string, name: string, location: array{lat: float, lng: float}, type: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'location' => $this->coordinates->toArray(),
            'type' => $this->type,
        ];
    }
}
