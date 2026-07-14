<?php

declare(strict_types=1);

namespace App\Domain\Trips\Actions;

use App\Domain\Trips\Enums\TripSegmentKind;
use App\Domain\Trips\Models\TripSegment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What kind of day was that? (E38; PRD §6.6.)
 *
 * ## The whole method, in one paragraph
 *
 * Take the trip's context events. Group them by local day. For each day ask three
 * questions with numeric answers — *how far did you end up from where you woke*
 * (net displacement), *how wide did the day get* (span), *how many tiles did you touch*
 * (distinct res-8 cells) — and let those three numbers pick a tempo. That is all it is.
 * There is no model, and deliberately so: this classification feeds the ranker, and a
 * ranker fed by a black box cannot be debugged by anybody, including us.
 *
 * ## Why net displacement and span are BOTH needed
 *
 * They disagree in exactly the interesting case, which is how you know they are both
 * doing work:
 *
 *   - A day driving Stockholm → Göteborg: net displacement 400 km, span 400 km. Travel.
 *   - A day walking every street in Visby: net displacement ~0 (you slept where you woke),
 *     span 3 km. Sightseeing — and net displacement alone would have called it relaxation.
 *   - A day on a beach: net displacement ~0, span ~400 m. Relaxation.
 *
 * A single number cannot separate the second from the third. Two can.
 *
 * ## What it refuses to do
 *
 * **It does not classify a day it did not see.** Trip Mode is consent-gated and off by
 * default (CONSENT §2A); a day with a handful of foreground pings is a day we mostly
 * missed, and the honest output is a low `confidence`, not a confident guess. `events`
 * is stored precisely so that a downstream reader can tell "we watched you cross Sweden"
 * apart from "we saw you twice and are extrapolating".
 */
final class InferTripSegments
{
    /**
     * Bump on any behavioural change (PRD §15). The replayer's ability to ask "would v2
     * have called the 14th a relaxation day?" depends on this being honest.
     */
    public const VERSION = 'v1';

    /** @return list<TripSegment> */
    public function __invoke(string $tripId, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $config = config('trips.segments');

        /*
         * One query, three aggregates, grouped by local day.
         *
         * `net_displacement_m` needs the FIRST and LAST point of each day, which is not an
         * aggregate SQL gives you — hence the window functions. `span_m` is the diagonal of
         * the day's bounding box: an honest approximation of "how wide did this day get"
         * that costs one ST_Extent instead of an O(n²) pairwise scan.
         */
        $days = DB::select(<<<'SQL'
            WITH events AS (
                SELECT
                    (occurred_at AT TIME ZONE 'UTC')::date AS day,
                    occurred_at,
                    location::geometry AS geom,
                    h3_index,
                    FIRST_VALUE(location::geometry) OVER w  AS first_geom,
                    LAST_VALUE(location::geometry)  OVER w  AS last_geom
                FROM context_events
                WHERE trip_id = ?
                  AND location IS NOT NULL
                WINDOW w AS (
                    PARTITION BY (occurred_at AT TIME ZONE 'UTC')::date
                    ORDER BY occurred_at
                    ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING
                )
            )
            SELECT
                day,
                MIN(occurred_at) AS starts_at,
                MAX(occurred_at) AS ends_at,
                COUNT(*)                          AS events,
                COUNT(DISTINCT h3_index)          AS distinct_cells,
                ROUND(MAX(ST_Distance(first_geom::geography, last_geom::geography)))   AS net_displacement_m,
                ROUND(ST_Distance(
                    ST_SetSRID(ST_MakePoint(ST_XMin(ST_Extent(geom)), ST_YMin(ST_Extent(geom))), 4326)::geography,
                    ST_SetSRID(ST_MakePoint(ST_XMax(ST_Extent(geom)), ST_YMax(ST_Extent(geom))), 4326)::geography
                ))                                AS span_m
            FROM events
            GROUP BY day
            ORDER BY day
        SQL, [$tripId]);

        $segments = [];

        foreach ($days as $day) {
            $net = (int) $day->net_displacement_m;
            $span = (int) $day->span_m;
            $cells = (int) $day->distinct_cells;
            $events = (int) $day->events;

            $kind = $this->classify($net, $span, $cells, $config);

            $segments[] = TripSegment::query()->updateOrCreate(
                ['trip_id' => $tripId, 'day' => $day->day, 'inference_version' => self::VERSION],
                [
                    'kind' => $kind,
                    'starts_at' => $day->starts_at,
                    'ends_at' => $day->ends_at,
                    'net_displacement_m' => $net,
                    'span_m' => $span,
                    'distinct_cells' => min(65535, $cells),
                    'events' => min(65535, $events),
                    'confidence' => $this->confidence($events, $config),
                ],
            );
        }

        return $segments;
    }

    /**
     * Three numbers in, one tempo out. Ordered most-decisive first.
     */
    private function classify(int $net, int $span, int $cells, array $config): TripSegmentKind
    {
        // You went somewhere. Nothing else about the day changes that.
        if ($net >= (int) $config['travel_min_net_displacement_m']) {
            return TripSegmentKind::Travel;
        }

        /*
         * You came back to where you started, but you covered ground doing it. The cell
         * count is the tiebreaker that stops a single long taxi ride out and back from
         * reading as a day of exploring: a res-8 cell is ~0.75 km², so touching six of them
         * means you were *in* places, not just passing through them.
         */
        if ($span >= (int) $config['sightseeing_min_span_m'] || $cells >= (int) $config['sightseeing_min_cells']) {
            return TripSegmentKind::Sightseeing;
        }

        return TripSegmentKind::Relaxation;
    }

    /**
     * How much of the day we actually saw.
     *
     * Trip Mode's meaningful-movement floor (RecordTripContext) fires at 250 m or 10
     * minutes, so an *active* watched day produces dozens of events. A day with three is a
     * day we mostly missed — and the classification of a day we mostly missed should be
     * held loosely, which is what this number is for.
     */
    private function confidence(int $events, array $config): float
    {
        $full = (int) $config['confident_at_events'];

        return round(min(1.0, $events / max(1, $full)), 3);
    }
}
