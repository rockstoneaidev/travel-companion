<?php

declare(strict_types=1);

namespace App\Domain\Curation\Services;

use App\Domain\Curation\Enums\CurationStatus;
use App\Domain\Curation\Models\CuratedItem;
use Illuminate\Support\Facades\DB;

/**
 * Curation's public read API (conventions/01): approved items only — the
 * review gate expressed as the ONLY query other modules can make. Tier A
 * after review (DATA-SOURCES §1.2).
 */
final class ApprovedCuratedItems
{
    /** @return list<array<string, mixed>> tile-scout candidate shape */
    public function forTile(string $h3Index): array
    {
        return CuratedItem::query()
            ->where('curated_items.status', CurationStatus::Approved)
            ->join('places_core', 'places_core.id', '=', 'curated_items.place_id')
            ->where('places_core.h3_index', $h3Index)
            ->orderBy('curated_items.id')
            ->get([
                'curated_items.id as curated_item_id', 'curated_items.title', 'curated_items.claim',
                'curated_items.facets as item_facets', 'curated_items.place_id',
                'places_core.name', 'places_core.type', 'places_core.type_domain', 'places_core.h3_index',
            ])
            ->map(static function ($row): array {
                $point = DB::selectOne(
                    'SELECT ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng FROM places_core WHERE id = ?',
                    [$row->place_id],
                );

                return [
                    'place_id' => $row->place_id,
                    'name' => $row->name,
                    'type' => $row->type,
                    'type_domain' => $row->type_domain,
                    'facets' => json_decode($row->item_facets, true),   // the curator's facets, not just priors
                    'lat' => (float) $point->lat,
                    'lng' => (float) $point->lng,
                    'h3_index' => $row->h3_index,
                    'scout' => 'curated',
                    'sources' => ['curated'],
                    'conflict_groups' => 0,
                    'age_days' => 0,
                    'curated_claim' => $row->claim,
                    'curated_item_id' => $row->curated_item_id,
                ];
            })
            ->all();
    }
}
