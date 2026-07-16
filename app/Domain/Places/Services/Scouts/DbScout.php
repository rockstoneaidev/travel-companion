<?php

declare(strict_types=1);

namespace App\Domain\Places\Services\Scouts;

use App\Domain\Places\Contracts\TileScout;
use App\Domain\Places\Models\Place;
use DateInterval;

/**
 * Base for the M1 scouts (E5): they read our own canonical places — free,
 * fast, and already licensed. Paid externals arrive as ScoutSource adapters
 * later and feed the same cache.
 */
abstract class DbScout implements TileScout
{
    public function version(): string
    {
        return 'v2'; // v2: candidates carry confidence inputs (sources, conflicts, freshness)
    }

    public function ttl(): DateInterval
    {
        // Own-DB reads are cheap; the cache exists for shape parity with paid
        // sources and for the shared-tile hit-rate metric. Static places: days.
        return new DateInterval('P1D');
    }

    /** @return list<array<string, mixed>> */
    protected function placesWhere(string $h3Index, ?array $domains = null): array
    {
        return Place::query()
            ->select(['id', 'name', 'type', 'type_domain', 'facets', 'h3_index', 'source_tags', 'attribute_sources', 'updated_at'])
            ->selectRaw('ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng')
            ->where('h3_index', $h3Index)
            ->when($domains !== null, fn ($q) => $q->whereIn('type_domain', $domains))
            ->orderBy('id')
            ->get()
            ->map(fn (Place $p): array => [
                'place_id' => $p->id,
                'name' => $p->name,
                'type' => $p->type?->value,
                'type_domain' => $p->type_domain?->value,
                'facets' => $p->facets->pluck('value')->all(),
                'lat' => (float) $p->getAttribute('lat'),
                'lng' => (float) $p->getAttribute('lng'),
                'h3_index' => $p->h3_index,
                'scout' => $this->key(),
                // OSM's own opening_hours, if the mapper tagged it (E50 cost lever). Read for
                // free here so the verify step can answer the easy cases without paying Google.
                'osm_opening_hours' => $p->source_tags['osm']['opening_hours'] ?? null,
                // Confidence inputs (SCORING §4.6), tile-scoped by design:
                'sources' => array_keys($p->source_tags),
                'conflict_groups' => count(($p->attribute_sources ?? [])['conflicts'] ?? []),
                'age_days' => max(0, (int) $p->updated_at?->diffInDays(now())),
            ])
            ->all();
    }
}
