<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Domain\Places\Data\Coverage;
use App\Domain\Trips\Enums\TravelMode;
use Illuminate\Support\Facades\DB;

/**
 * Mode-aware, anisotropic coverage (conventions/12 DECIDED, PRD §9.2):
 * resolution is fixed at res 8 — mode changes reach and shape, never tile
 * size. No direction → disc; heading → ±60° cone with a short tail;
 * destination → corridor. A res-6 aggregate prefilter drops empty countryside
 * before anything queries per tile.
 */
final class CoverageGeometry
{
    public function forSession(
        float $lat,
        float $lng,
        TravelMode $mode,
        int $timeBudgetMinutes,
        ?int $headingDeg = null,
        ?float $destLat = null,
        ?float $destLng = null,
    ): Coverage {
        $config = config('tiles');
        $modeConfig = $config['modes'][$mode->value];

        $reachM = $modeConfig['speed_kmh'] * 1000 * ($timeBudgetMinutes / 60) * $modeConfig['outbound_fraction'];
        $originCell = $this->cellFor($lat, $lng);

        $cells = match (true) {
            $destLat !== null && $destLng !== null => $this->corridor($lat, $lng, $destLat, $destLng, (int) $modeConfig['corridor_width_m']),
            $headingDeg !== null => $this->cone($originCell, $lat, $lng, $reachM, $headingDeg),
            default => $this->disc($originCell, $reachM),
        };

        $cells = $this->prefilterByRes6($cells);

        // Near ring: walking-scale around origin (and destination) — the tiles
        // every source scouts regardless of mode (conventions/09).
        $near = $this->disc($originCell, (float) $config['coverage']['near_ring_m']);
        if ($destLat !== null && $destLng !== null) {
            $near = [...$near, ...$this->disc($this->cellFor($destLat, $destLng), (float) $config['coverage']['near_ring_m'])];
        }
        $near = array_values(array_unique($near));

        $nearSet = array_flip($near);
        $far = array_values(array_filter($cells, static fn (string $c): bool => ! isset($nearSet[$c])));
        $nearInCoverage = array_values(array_intersect($near, [...$cells, ...$near]));

        return new Coverage($originCell, $mode->value, $nearInCoverage, $far);
    }

    private function cellFor(float $lat, float $lng): string
    {
        return DB::selectOne(
            'SELECT h3_lat_lng_to_cell(POINT(?, ?), ?)::text AS cell',
            [$lng, $lat, config('tiles.resolution')],
        )->cell;
    }

    /** @return list<string> */
    private function disc(string $originCell, float $reachM): array
    {
        $k = min((int) config('tiles.coverage.max_k'), max(1, (int) ceil($reachM / config('tiles.cell_spacing_m'))));

        return array_column(DB::select(
            'SELECT h3_grid_disk(?::h3index, ?)::text AS cell',
            [$originCell, $k],
        ), 'cell');
    }

    /** @return list<string> Pear shape: full reach within ±60° ahead, ~40% behind. */
    private function cone(string $originCell, float $lat, float $lng, float $reachM, int $headingDeg): array
    {
        $halfAngle = (float) config('tiles.coverage.cone_half_angle_deg');
        $behind = (float) config('tiles.coverage.behind_fraction');

        $rows = DB::select(
            'SELECT c::text AS cell,
                    ST_Distance(h3_cell_to_geometry(c)::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS dist,
                    degrees(ST_Azimuth(ST_SetSRID(ST_MakePoint(?, ?), 4326), h3_cell_to_geometry(c))) AS bearing
             FROM h3_grid_disk(?::h3index, ?) AS c',
            [$lng, $lat, $lng, $lat, $originCell, min((int) config('tiles.coverage.max_k'), max(1, (int) ceil($reachM / config('tiles.cell_spacing_m'))))],
        );

        $cells = [];
        foreach ($rows as $row) {
            if ($row->bearing === null) { // the origin cell itself
                $cells[] = $row->cell;

                continue;
            }

            $delta = abs(fmod((float) $row->bearing - $headingDeg + 540.0, 360.0) - 180.0);
            $allowed = $delta <= $halfAngle ? $reachM : $reachM * $behind;

            if ((float) $row->dist <= $allowed) {
                $cells[] = $row->cell;
            }
        }

        return $cells;
    }

    /** @return list<string> One polygon fill along the origin→destination line — the fetch unit is the corridor, never per-tile calls. */
    private function corridor(float $lat, float $lng, float $destLat, float $destLng, int $widthM): array
    {
        return array_column(DB::select(
            'SELECT h3_polygon_to_cells(
                        ST_Buffer(ST_MakeLine(ST_SetSRID(ST_MakePoint(?, ?), 4326), ST_SetSRID(ST_MakePoint(?, ?), 4326))::geography, ?)::geometry,
                        ?
                    )::text AS cell',
            [$lng, $lat, $destLng, $destLat, $widthM, config('tiles.resolution')],
        ), 'cell');
    }

    /**
     * Hierarchical prefilter (conventions/12): only keep res-8 cells whose
     * res-6 parent holds any canonical places — empty countryside costs nothing.
     *
     * @param  list<string>  $cells
     * @return list<string>
     */
    private function prefilterByRes6(array $cells): array
    {
        if ($cells === []) {
            return [];
        }

        $occupied = array_flip(array_column(DB::select(
            'SELECT DISTINCT h3_cell_to_parent(h3_index::h3index, 6)::text AS parent FROM places_core',
        ), 'parent'));

        $parents = DB::select(
            'SELECT c AS cell, h3_cell_to_parent(c::h3index, 6)::text AS parent FROM unnest(?::text[]) AS c',
            ['{'.implode(',', $cells).'}'],
        );

        $kept = [];
        foreach ($parents as $row) {
            if (isset($occupied[$row->parent])) {
                $kept[] = $row->cell;
            }
        }

        return $kept;
    }
}
