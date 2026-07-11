<?php

declare(strict_types=1);

namespace App\Domain\Sources\Adapters;

use App\Domain\Places\Taxonomy\OvertureCategoryMap;
use App\Domain\Sources\Adapters\Concerns\BuildsCandidates;
use App\Domain\Sources\Contracts\ScoutSource;
use App\Domain\Sources\Data\ScoutRequest;
use DateInterval;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Overture Maps places adapter (DATA-SOURCES §2): the broad legal POI base.
 *
 * Overture ships as cloud-hosted GeoParquet; the supported fetch is the
 * `overturemaps` CLI, run once per region:
 *
 *   overturemaps download --bbox={west},{south},{east},{north} -f geojson \
 *     --type=place -o storage/app/ingest/overture/{region}.geojson
 *
 * search() reads that extract (its only I/O); normalize() is pure over the
 * GeoJSON features. A missing extract is a degraded result for the region,
 * not a pipeline failure (conventions/09 — coverage honesty).
 */
final class OvertureAdapter implements ScoutSource
{
    use BuildsCandidates;

    public const KEY = 'overture';

    public const VERSION = 'v1';

    public function supports(ScoutRequest $request): bool
    {
        return Storage::disk('local')->exists($this->extractPath($request->regionKey));
    }

    public function search(ScoutRequest $request): array
    {
        $path = $this->extractPath($request->regionKey);

        if (! Storage::disk('local')->exists($path)) {
            throw new RuntimeException(sprintf(
                'Overture extract missing for region "%s". Fetch it with: overturemaps download --bbox=%F,%F,%F,%F -f geojson --type=place -o storage/app/%s',
                $request->regionKey, $request->west, $request->south, $request->east, $request->north, $path,
            ));
        }

        $geojson = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);

        return $geojson['features'] ?? [];
    }

    public function normalize(array $raw): array
    {
        $candidates = [];

        foreach ($raw as $feature) {
            $props = $feature['properties'] ?? [];
            $category = $props['categories']['primary'] ?? null;
            $type = $category === null ? null : OvertureCategoryMap::map($category);

            if ($type === null) {
                continue;
            }

            $name = $props['names']['primary'] ?? null;
            if ($name === null) {
                continue;
            }

            $coords = $feature['geometry']['coordinates'] ?? null;
            if (! is_array($coords) || count($coords) < 2) {
                continue;
            }

            $common = $props['names']['common'] ?? [];

            $candidates[] = $this->candidate(
                externalId: (string) ($props['id'] ?? $feature['id'] ?? ''),
                name: $name,
                altNames: array_filter(array_values(is_array($common) ? $common : [])),
                lat: (float) $coords[1],
                lng: (float) $coords[0],
                type: $type,
                sourceTags: [
                    'categories' => $props['categories'] ?? [],
                    'confidence' => $props['confidence'] ?? null,
                ],
                externalRefs: [], // GERS bridge refs arrive with ER Stage 1 (ENTITY-RESOLUTION §3)
                language: 'sv',
            );
        }

        return array_values(array_filter($candidates, fn (array $c): bool => $c['external_id'] !== ''));
    }

    public function ttl(): DateInterval
    {
        return new DateInterval('P30D');
    }

    private function extractPath(string $regionKey): string
    {
        return "ingest/overture/{$regionKey}.geojson";
    }
}
