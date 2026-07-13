<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `explore_sessions.origin_h3_index` for every session that has an origin but
 * no cell — which, before this, was every session ever created.
 *
 * The column was added by the E4 migration with the note "E5 fills this; E17 coarsens to
 * it". E17 (the retention pass) did its half. E5 never did its half, and nothing in
 * `app/` ever wrote the column, so the only thing that ever set it was the nightly
 * coarsening — which back-fills the cell from `origin` at 30 days, on its way to deleting
 * the coordinate. A live session therefore carried a NULL cell for its entire useful life
 * and acquired one a month after it stopped mattering.
 *
 * The consequence was not cosmetic: `BuildDigest::lede()` reads this column and passes it
 * to the weather client, so the morning dashboard raised
 * `h3_cell_to_geometry(''::h3index)` for any user who had ever started a session.
 *
 * StartExploreSession now writes the cell at creation. This fixes the rows that already
 * exist, using the identical Postgres expression the retention pass uses — same function,
 * same resolution (8, conventions/12), so a back-filled row and a freshly-written one are
 * indistinguishable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update(
            "UPDATE explore_sessions
                SET origin_h3_index = h3_lat_lng_to_cell(
                        POINT(ST_X(origin::geometry), ST_Y(origin::geometry)), 8
                    )::text
              WHERE origin IS NOT NULL
                AND (origin_h3_index IS NULL OR origin_h3_index = '')"
        );
    }

    public function down(): void
    {
        // Deliberately empty. Re-introducing a NULL here would restore a crash, and there
        // is no state worth rolling back TO — the column being empty was never intended.
    }
};
