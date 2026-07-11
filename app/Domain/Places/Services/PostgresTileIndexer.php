<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Domain\Places\Contracts\TileIndexer;
use Illuminate\Support\Facades\DB;

/**
 * h3-pg-backed TileIndexer. The database owns the H3 math (no PHP H3 port
 * exists worth trusting); ingest batches points to keep round-trips rare.
 */
final class PostgresTileIndexer implements TileIndexer
{
    private const RESOLUTION = 8; // conventions/12 — the canonical tile

    public function cellFor(float $lat, float $lng): string
    {
        return $this->cellsFor([[$lat, $lng]])[0];
    }

    public function cellsFor(array $points): array
    {
        if ($points === []) {
            return [];
        }

        $cells = [];
        foreach (array_chunk($points, 5000) as $chunk) {
            $lats = implode(',', array_map(static fn (array $p): float => $p[0], $chunk));
            $lngs = implode(',', array_map(static fn (array $p): float => $p[1], $chunk));

            $rows = DB::select(
                'SELECT h3_lat_lng_to_cell(POINT(lng, lat), ?)::text AS cell
                 FROM unnest(ARRAY['.$lngs.']::float8[], ARRAY['.$lats.']::float8[]) AS t(lng, lat)',
                [self::RESOLUTION],
            );

            foreach ($rows as $row) {
                $cells[] = $row->cell;
            }
        }

        return $cells;
    }
}
