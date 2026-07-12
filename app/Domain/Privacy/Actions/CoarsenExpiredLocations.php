<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Actions;

use App\Domain\Privacy\Data\RetentionReport;
use Illuminate\Support\Facades\DB;

/**
 * Storage limitation, executed (PRD §16, GDPR Art. 5).
 *
 * After the retention window, raw precise location is coarsened to its H3 res-8
 * cell and the coordinates are HARD-deleted. Not soft-deleted, not moved to an
 * archive table, not "excluded from queries": the point of storage limitation is
 * that the data is gone.
 *
 * COARSENED, not erased — and the difference is the whole design. We keep the
 * cell (where, roughly) and the derived signals (movement mode, dwell class),
 * because those are what the pipeline actually learns from. We drop the
 * coordinate, because that is what identifies a person's doorway. Erasing both
 * would throw away the product; keeping both would throw away the promise.
 *
 * The coarsening runs in the DATABASE, in one statement per table. Loading a
 * month of pings into PHP to null a column would be slower, and would mean the
 * precise coordinates of every user briefly existing in application memory —
 * which is not a thing you want to be true of your retention job.
 */
final class CoarsenExpiredLocations
{
    public function __invoke(): RetentionReport
    {
        $days = (int) config('privacy.raw_location_retention_days');
        $cutoff = now()->subDays($days);

        return DB::transaction(function () use ($cutoff): RetentionReport {
            /*
             * Context events: fill the cell from the coordinate we are about to
             * destroy, then destroy it. Order matters — coarsen first, delete second,
             * in one statement, so there is no window in which the row has lost its
             * precision without having gained its cell.
             */
            $contextEvents = DB::update(
                'UPDATE context_events
                    SET h3_index = COALESCE(
                            h3_index,
                            h3_lat_lng_to_cell(POINT(ST_X(location::geometry), ST_Y(location::geometry)), 8)::text
                        ),
                        location = NULL,
                        accuracy_meters = NULL
                  WHERE location IS NOT NULL
                    AND occurred_at < ?',
                [$cutoff],
            );

            // Session origins. `origin_h3_index` was designed for exactly this — the
            // migration that created it says so ("E5 fills this; E17 coarsens to it").
            $sessions = DB::update(
                'UPDATE explore_sessions
                    SET origin_h3_index = COALESCE(
                            origin_h3_index,
                            h3_lat_lng_to_cell(POINT(ST_X(origin::geometry), ST_Y(origin::geometry)), 8)::text
                        ),
                        origin = NULL,
                        destination_point = NULL
                  WHERE origin IS NOT NULL
                    AND started_at < ?',
                [$cutoff],
            );

            // A trip's anchor is a coordinate like any other.
            $trips = DB::update(
                'UPDATE trips SET anchor_point = NULL WHERE anchor_point IS NOT NULL AND created_at < ?',
                [$cutoff],
            );

            return new RetentionReport(
                contextEvents: $contextEvents,
                sessions: $sessions,
                trips: $trips,
                traces: 0,
            );
        });
    }
}
