<?php

declare(strict_types=1);

namespace App\Domain\Places\Queries;

use Illuminate\Support\Facades\DB;

/**
 * H3 cells → drawable polygons (E47, ADMIN §6).
 *
 * `CoverageGeometry` has always been able to compute exactly what the emulator wants
 * to draw — the disc when wandering, the cone ahead of a heading, the corridor to a
 * destination (conventions/12) — but it returns a list of cell INDEXES, and nothing in
 * the codebase had ever asked H3 for a hex's boundary. Every existing caller wanted a
 * centroid (`h3_cell_to_geometry`), because every existing caller was doing arithmetic,
 * not cartography.
 *
 * So the coverage shape was computable and invisible: the pipeline knew precisely which
 * 0.74 km² hexagons it was about to scout and had no way to show anyone. This is the
 * missing half-line of SQL, and it is why the emulator can render the cone re-aiming as
 * the pin walks rather than describing it in a log line.
 */
final class TileBoundaries
{
    /**
     * GeoJSON polygons for the given res-8 cells, keyed by cell index.
     *
     * @param  list<string>  $cells
     * @return array<string, array<string, mixed>>
     */
    public function forCells(array $cells): array
    {
        if ($cells === []) {
            return [];
        }

        /*
         * One query for the whole coverage, not one per hex. A drive corridor is
         * thousands of cells (conventions/12: "the tile is the cache and accounting
         * unit, not the fetch unit") and a per-cell round trip would make the overlay
         * the slowest thing on the screen.
         */
        $rows = DB::select(
            'SELECT c AS cell, ST_AsGeoJSON(h3_cell_to_boundary_geometry(c::h3index)) AS geojson
               FROM unnest(?::text[]) AS c',
            ['{'.implode(',', $cells).'}'],
        );

        $out = [];

        foreach ($rows as $row) {
            $geometry = json_decode((string) $row->geojson, true);

            if (is_array($geometry)) {
                $out[$row->cell] = $geometry;
            }
        }

        return $out;
    }
}
