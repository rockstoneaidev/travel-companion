<?php

declare(strict_types=1);

namespace App\Domain\Sources\Actions;

use App\Domain\Sources\Data\IngestRegion;
use App\Domain\Sources\Models\DerivedRegion;
use App\Domain\Sources\Services\RegionCatalog;
use App\Domain\Sources\Services\ReverseGeocoder;
use Illuminate\Support\Facades\DB;

/**
 * Mint the region around a position we have never heard of (E48).
 *
 * ## Identity comes from the GRID, not from the pin
 *
 * The first version centred the region box on the user's own coordinate. It produced
 * exactly the garbage you would expect the moment two people stood in different parts of
 * the same town:
 *
 *     Skellefteå         skelleftea-6475-2095          200 places, box 41 of 55
 *     Skellefteå kommun  skelleftea-kommun-6488-2080     3 places, queued
 *
 * Two overlapping regions, for one town, ingesting the same Overpass ground twice — and
 * under *different names*, because Nominatim answers `city` in a town and falls back to
 * `municipality` out in the countryside. A pin 15 km north minted a whole new region.
 *
 * The name was the symptom. **Identity was the disease.** A region anchored to an
 * arbitrary point is a sliding window: it never tiles anything, it only accumulates.
 *
 * So identity is an **H3 res-5 cell** (~250 km², ~17 km across — comparable to the
 * hand-drawn Stockholm region's 584 km², and ~28 Overpass boxes instead of 55). Cells
 * TILE THE PLANE, which buys three things at once and costs nothing:
 *
 *   - **Deterministic.** Any pin in the cell derives the same region, with the same key
 *     and the same box. Twice is the same as once.
 *   - **Self-deduplicating.** Containment in a cell is exact and total. There is no box
 *     edge to fall just outside of — which is precisely how Kusmark, 2 km north of the
 *     first box, minted a second Skellefteå.
 *   - **Expands outward as people move.** Walk into the next cell and we learn the next
 *     cell. No overlap, ever.
 *
 * The NAME is then purely cosmetic — a label on a cell. It is looked up separately, at
 * res-8 (see below), because naming and identity are different questions and a res-5
 * centroid can land in a bog.
 *
 * The honest trade-off: someone standing at a cell edge has part of their reach in an
 * unlearned neighbour. At walking scale (3–4 km of reach inside a 17 km cell) that is
 * rare, and moving there triggers it. At driving scale no region size is ever enough,
 * which is a different problem and belongs to E49 (self-hosted Overpass).
 */
final class DeriveRegionForPosition
{
    /**
     * H3 res 5: ~252 km², edge ~8.5 km, bbox ~17 km across.
     *
     * Res 4 would be ~1,770 km² — a hundred-plus Overpass boxes and mostly forest, which
     * is the Skellefteå-*kommun* mistake made on purpose. Res 6 (~36 km²) would mint a new
     * region every twenty minutes of walking.
     */
    private const RESOLUTION = 5;

    public function __construct(
        private readonly RegionCatalog $catalog,
        private readonly ReverseGeocoder $geocoder,
    ) {}

    /** The region covering this point — the existing one if there is one, a new one if not. */
    public function __invoke(float $lat, float $lng, ?int $userId = null): IngestRegion
    {
        $existing = $this->catalog->covering($lat, $lng);

        if ($existing !== null) {
            return $existing;
        }

        $cell = $this->cell($lat, $lng);

        // Two people walking into the same cell from opposite sides must not both mint it.
        $already = DerivedRegion::query()->where('key', $this->key($cell))->first();

        if ($already !== null) {
            return $already->toIngestRegion();
        }

        $box = $this->boundingBox($cell);

        /*
         * IDENTITY IS RES-5. THE NAME IS LOOKED UP AT RES-8. They are different questions.
         *
         * Identity wants a coarse grid, so that a town is one region however people wander
         * into it. A NAME wants to be near the thing it is naming: a res-5 cell is ~17 km
         * across, and its centroid can easily land in forest — so naming the region from it
         * would christen Skellefteå after a bog eight kilometres away.
         *
         * So the label is asked at the res-8 cell the pin sits in (~0.74 km²): close enough
         * to name the right town, and still a hexagon rather than a person (PRD §16, ROPA
         * §6.1 — Nominatim must never receive somebody's actual coordinate).
         *
         * The name is computed once, when the region is minted, and never again — so it is
         * stable thereafter even though it came from whoever arrived first. It is a label
         * on a cell. Nothing depends on it.
         */
        $described = $this->geocoder->forTile($this->cellAt($lat, $lng, 8));

        $region = DerivedRegion::query()->create([
            'key' => $this->key($cell),
            'name' => $described['name'],
            'south' => round($box['south'], 6),
            'west' => round($box['west'], 6),
            'north' => round($box['north'], 6),
            'east' => round($box['east'], 6),
            'locale' => $described['locale'],
            'requested_by_user_id' => $userId,
            'requested_at' => now(),
        ]);

        return $region->toIngestRegion();
    }

    /**
     * `r5-8508f02ffffffff` — the cell IS the identity.
     *
     * The old key carried a slug and rounded coordinates (`skelleftea-6475-2095`), which
     * looked friendlier and meant nothing: two of them could name the same town and
     * neither could tell you so.
     */
    private function key(string $cell): string
    {
        return 'r'.self::RESOLUTION.'-'.$cell;
    }

    private function cell(float $lat, float $lng): string
    {
        return $this->cellAt($lat, $lng, self::RESOLUTION);
    }

    private function cellAt(float $lat, float $lng, int $resolution): string
    {
        return (string) DB::scalar(
            'SELECT h3_lat_lng_to_cell(POINT(?, ?), ?)::text',
            [$lng, $lat, $resolution],
        );
    }

    /** @return array{south: float, west: float, north: float, east: float} */
    private function boundingBox(string $cell): array
    {
        $row = DB::selectOne(
            'SELECT ST_YMin(b) AS south, ST_XMin(b) AS west, ST_YMax(b) AS north, ST_XMax(b) AS east
               FROM (SELECT ST_Envelope(h3_cell_to_boundary_geometry(?::h3index)) AS b) t',
            [$cell],
        );

        return [
            'south' => (float) $row->south,
            'west' => (float) $row->west,
            'north' => (float) $row->north,
            'east' => (float) $row->east,
        ];
    }
}
