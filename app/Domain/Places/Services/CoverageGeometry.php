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

        $isCorridor = $destLat !== null && $destLng !== null;

        $cells = match (true) {
            $isCorridor => $this->corridor($lat, $lng, $destLat, $destLng, (int) $modeConfig['corridor_width_m']),
            $headingDeg !== null => $this->cone($originCell, $lat, $lng, $reachM, $headingDeg),
            default => $this->disc($originCell, $reachM),
        };

        $cells = $this->prefilterByRes6($cells);

        /*
         * THE CORRIDOR BUDGET (E35).
         *
         * `corridor()` returns cells ordered by progress along the route, so "the road
         * ahead of you" is simply a prefix of the list. We scout that prefix inline and
         * hand the rest to the queue.
         *
         * Note what we deliberately do NOT do: truncate the corridor at `reachM`. Reach
         * is "how far can I get and still come home", which is the right question for a
         * disc and the wrong one for a corridor — a driver bound for Göteborg is going to
         * Göteborg whether or not it fits in their outbound budget. Truncating there would
         * silently amputate the second half of every road trip. The constraint that
         * actually binds is not distance, it is *how many tiles we may scout before the
         * traveller gets bored*, and that is a count.
         */
        $pending = [];

        if ($isCorridor) {
            $inline = (int) $config['coverage']['max_inline_tiles'];
            $ahead = (int) $config['coverage']['max_prescout_tiles'];

            $pending = array_slice($cells, $inline, $ahead);
            $cells = array_slice($cells, 0, $inline);
        }

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

        // A tile we are about to scout inline must not also be queued for pre-scouting:
        // WarmTileJob is idempotent, but paying a worker to re-derive an answer the
        // request already has is just noise in the hit-rate metric.
        $inlineSet = array_flip([...$nearInCoverage, ...$far]);
        $pending = array_values(array_filter($pending, static fn (string $c): bool => ! isset($inlineSet[$c])));

        return new Coverage($originCell, $mode->value, $nearInCoverage, $far, $pending);
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

    /**
     * One polygon fill along the origin→destination line — the fetch unit is the
     * corridor, never per-tile calls (conventions/12) — **ordered by progress along
     * the route** (E35).
     *
     * The ordering is the whole feature. Without it, "the corridor" is an unordered
     * bag of thousands of cells and the only sane thing to do with it is scout all of
     * them, which is exactly what we cannot afford. With it, the bag becomes a
     * *sequence*: the next few kilometres are a prefix, and the tail is something a
     * queue can chew through while the car is still driving toward it.
     *
     * `ST_LineLocatePoint` gives the fraction along the line of the point on it
     * closest to a cell's centre; multiplied by the line's geographic length that is
     * "how far down this road is this tile", in metres. Cells abreast of each other
     * across the corridor's width sort together, which is the correct behaviour: they
     * become relevant at the same moment.
     *
     * @return list<string>
     */
    private function corridor(float $lat, float $lng, float $destLat, float $destLng, int $widthM): array
    {
        return array_column(DB::select(
            'WITH route AS (
                 SELECT ST_MakeLine(
                            ST_SetSRID(ST_MakePoint(?, ?), 4326),
                            ST_SetSRID(ST_MakePoint(?, ?), 4326)
                        ) AS line
             )
             SELECT c::text AS cell
             FROM route,
                  h3_polygon_to_cells(ST_Buffer(route.line::geography, ?)::geometry, ?) AS c
             ORDER BY ST_LineLocatePoint(route.line, h3_cell_to_geometry(c))',
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

        // WITH ORDINALITY + ORDER BY, not a bare unnest: the corridor's cells arrive
        // sorted by progress along the route (E35), and the prefilter is a *filter* —
        // it may drop cells, it may not reorder them, or the tile budget would slice
        // an arbitrary chunk out of the middle of the road instead of the next few km.
        $parents = DB::select(
            'SELECT c AS cell, h3_cell_to_parent(c::h3index, 6)::text AS parent
             FROM unnest(?::text[]) WITH ORDINALITY AS t(c, ord)
             ORDER BY t.ord',
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
