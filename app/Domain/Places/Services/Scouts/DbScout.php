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
        return 'v1';
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
            ->select(['id', 'name', 'type', 'type_domain', 'facets', 'h3_index'])
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
            ])
            ->all();
    }
}
