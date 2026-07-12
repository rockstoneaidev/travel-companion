<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Domain\Sources\Data\IngestRegion;
use Illuminate\Support\Facades\DB;

/**
 * Region-scoped resolution (extracted from the resolve:region command so jobs
 * and the admin console share it). Batchable: callers pass a tile cap and
 * loop until no unresolved tiles remain — resolver idempotency (per
 * resolver_version decisions) makes re-entry free.
 */
final class ResolveRegion
{
    public function __construct(
        private readonly EntityResolver $resolver,
    ) {}

    /** @return list<string> */
    public function unresolvedTiles(IngestRegion $region, int $limit = 1000): array
    {
        return DB::table('source_items')
            ->selectRaw('DISTINCT source_items.h3_index')
            ->whereRaw(
                'ST_Intersects(location::geometry, ST_MakeEnvelope(?, ?, ?, ?, 4326))',
                [$region->west, $region->south, $region->east, $region->north],
            )
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('place_match_decisions')
                    ->whereColumn('place_match_decisions.source_item_id', 'source_items.id')
                    ->where('place_match_decisions.resolver_version', config('resolver.version'));
            })
            ->orderBy('h3_index')
            ->limit($limit)
            ->pluck('h3_index')
            ->all();
    }

    /**
     * Every tile holding a canonical place in the region — what the shared tile
     * cache needs pre-warmed (PRD §9.3).
     *
     * @return list<string>
     */
    public function tilesFor(IngestRegion $region): array
    {
        return DB::table('places_core')
            ->selectRaw('DISTINCT h3_index')
            ->whereRaw(
                'ST_Intersects(location::geometry, ST_MakeEnvelope(?, ?, ?, ?, 4326))',
                [$region->west, $region->south, $region->east, $region->north],
            )
            ->orderBy('h3_index')
            ->pluck('h3_index')
            ->all();
    }

    /**
     * @param  list<string>  $tiles
     * @return array{items: int, created: int, merged: int, review: int, explicit: int}
     */
    public function resolveTiles(array $tiles): array
    {
        $totals = ['items' => 0, 'created' => 0, 'merged' => 0, 'review' => 0, 'explicit' => 0];

        foreach ($tiles as $tile) {
            foreach ($this->resolver->resolveTile($tile) as $key => $value) {
                $totals[$key] += $value;
            }
        }

        return $totals;
    }
}
