<?php

declare(strict_types=1);

namespace App\Domain\Feedback\Actions;

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Privacy\Services\HomeZone;
use App\Domain\Recommendations\Contracts\FeedbackRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "You went." (E37; PRD §7.1, §13.3.)
 *
 * ## Why this is the point of background location
 *
 * The north star is *"I would have missed this" moments per trip-day* — recommendations
 * accepted, **actually visited**, and rated well. Everything else in the product is
 * upstream of that word: *visited*.
 *
 * Phase 1 could only ask. The visit prompt ("did you make it?") is honest, and it works,
 * and it has the fatal property of every self-report ever collected: the people who answer
 * are not the people who don't. A golden label gathered by asking is a golden label about
 * the kind of person who answers prompts.
 *
 * A dwell is not a self-report. If somebody stood inside a churchyard for twenty minutes,
 * they went to the church, and no amount of not-tapping-a-button changes that. This is the
 * label going DENSE — from "the fraction of visits somebody bothered to confirm" to "the
 * visits", and it is the single biggest quality lever Phase 2 has.
 *
 * ## What a dwell is
 *
 * Consecutive context events that stay inside a small radius for long enough to mean you
 * stopped rather than paused. Both halves matter and both are configurable:
 *
 *   - **radius** — small enough that the café next door is a different dwell.
 *   - **duration** — long enough that a red light, a lookup of the map, and a pause to
 *     take a photograph do not count as having visited a museum.
 *
 * ## The three refusals
 *
 * 1. **Never in a home zone.** This cannot even happen — `RecordTripContext` drops those
 *    events before they are written, so there is nothing here to cluster. The check below
 *    is belt-and-braces against a *future* ingestion path that forgets, and it is cheap.
 *
 * 2. **Never overrule a human.** If the traveller was asked and said no, they did not go,
 *    whatever the coordinates think. A GPS fix that puts somebody in a building they say
 *    they never entered is a GPS fix, not a fact.
 *
 * 3. **Never double-count.** A detected visit and a confirmed visit of the same
 *    recommendation are one visit. The learner sees `visited` once (η .30, SCORING §4.1) —
 *    a label that fires twice is a label with twice the weight, which is a silent bug in
 *    the taste model rather than a loud one anywhere.
 */
final class DetectVisits
{
    public const VERSION = 'v1';

    public function __construct(
        /*
         * Through the published door (conventions/01), NOT the raw ledger.
         *
         * `RecordFeedback` — which is what sits behind this contract — carries three things
         * a direct insert would silently skip, and one of them would have been a real bug:
         *
         *   - the EMULATED-CONTEXT GATE. An operator driving a synthetic walk through the
         *     position emulator generates real dwells over real places. Writing straight to
         *     the ledger would have taught the founder's own taste profile from a debugging
         *     session (ADMIN §6), and the profile learns from sparse data, so it would have
         *     shown up in real recommendations for weeks.
         *   - the retraction logic (a `visited` that contradicts an earlier state).
         *   - the learner itself, which is the entire point of a golden label.
         */
        private readonly FeedbackRecorder $feedback,
        private readonly HomeZone $homeZone,
    ) {}

    /**
     * @return list<string> recommendation ids newly marked visited
     */
    public function __invoke(string $tripId, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $config = config('feedback.visit_detection');

        $dwells = $this->dwells($tripId, (int) $config['dwell_radius_m'], (int) $config['min_dwell_minutes']);

        if ($dwells === []) {
            return [];
        }

        $detected = [];

        foreach ($dwells as $dwell) {
            foreach ($this->recommendationsNear($tripId, $dwell, (int) $config['match_radius_m']) as $recommendation) {
                if ($this->alreadySettled($recommendation->id)) {
                    continue;
                }

                if ($this->homeZone->declared() && $this->homeZone->contains((float) $dwell->lat, (float) $dwell->lng)) {
                    continue;
                }

                $this->feedback->record(
                    $recommendation->id,
                    FeedbackEvent::Visited,
                    [
                        // The label says how it was obtained. A model trained on these will
                        // one day want to weigh a detection differently from a confirmation,
                        // and it can only do that if we wrote down which was which.
                        'source' => 'detected',
                        'detector_version' => self::VERSION,
                        'dwell_minutes' => round((float) $dwell->minutes, 1),
                        'distance_m' => round((float) $recommendation->distance_m),
                    ],
                    CarbonImmutable::parse($dwell->ends_at),
                );

                $detected[] = $recommendation->id;
            }
        }

        return $detected;
    }

    /**
     * Cluster the trip's events into dwells.
     *
     * The algorithm is a single pass with a running anchor: an event within `radius` of the
     * current dwell's anchor extends it; an event outside starts a new one. That is a
     * deliberately simple rule, and it is the right one — a smarter clusterer (DBSCAN, and
     * we could) would happily merge two adjacent cafés into a single blob and then be
     * unable to say which one you sat in.
     *
     * Done in SQL because the alternative is dragging a day of a person's movements into
     * PHP memory to compute three numbers about them.
     *
     * @return list<object{lat: float, lng: float, starts_at: string, ends_at: string, minutes: float}>
     */
    private function dwells(string $tripId, int $radiusM, int $minMinutes): array
    {
        return DB::select(<<<'SQL'
            WITH ordered AS (
                SELECT
                    occurred_at,
                    location::geometry AS geom,
                    LAG(location::geometry) OVER (ORDER BY occurred_at) AS prev_geom
                FROM context_events
                WHERE trip_id = ?
                  AND location IS NOT NULL
            ),
            marked AS (
                -- A new dwell begins whenever we moved further than the radius since the
                -- last fix. Everything else is a continuation of the same stop.
                SELECT *,
                       CASE
                           WHEN prev_geom IS NULL THEN 1
                           WHEN ST_Distance(geom::geography, prev_geom::geography) > ? THEN 1
                           ELSE 0
                       END AS is_new
                FROM ordered
            ),
            grouped AS (
                SELECT *, SUM(is_new) OVER (ORDER BY occurred_at) AS dwell_id
                FROM marked
            )
            SELECT
                ST_Y(ST_Centroid(ST_Collect(geom)))  AS lat,
                ST_X(ST_Centroid(ST_Collect(geom)))  AS lng,
                MIN(occurred_at)                     AS starts_at,
                MAX(occurred_at)                     AS ends_at,
                EXTRACT(EPOCH FROM (MAX(occurred_at) - MIN(occurred_at))) / 60.0 AS minutes
            FROM grouped
            GROUP BY dwell_id
            HAVING EXTRACT(EPOCH FROM (MAX(occurred_at) - MIN(occurred_at))) / 60.0 >= ?
            ORDER BY MIN(occurred_at)
        SQL, [$tripId, $radiusM, $minMinutes]);
    }

    /**
     * Which of the things we RECOMMENDED is this person standing in?
     *
     * The geometry lives on the PLACE, not the opportunity — an opportunity is a
     * time-bound thing that happens *at* a place, and it carries `place_id` and an H3
     * cell, never a coordinate of its own. So the join goes through `places_core`.
     *
     * Only recommendations, never all places — this is a feedback signal about our own
     * decisions, not a life-log. We are not building a record of everywhere somebody went;
     * we are answering "did the thing we suggested turn out to be worth going to", and
     * that question is only asked of things we suggested.
     *
     * @return list<object{id: string, distance_m: float}>
     */
    private function recommendationsNear(string $tripId, object $dwell, int $matchRadiusM): array
    {
        return DB::select(<<<'SQL'
            SELECT r.id,
                   ST_Distance(p.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS distance_m
            FROM recommendations r
            JOIN opportunities o ON o.id = r.opportunity_id
            JOIN places_core p   ON p.id = o.place_id
            WHERE r.trip_id = ?
              AND r.served_at IS NOT NULL
              AND r.served_at <= ?
              AND ST_DWithin(p.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)
            ORDER BY distance_m
        SQL, [
            $dwell->lng, $dwell->lat,
            $tripId,
            $dwell->ends_at,
            $dwell->lng, $dwell->lat, $matchRadiusM,
        ]);
    }

    /**
     * Has a human already spoken about this one, either way?
     *
     * `visited` — they confirmed it, or we already detected it. Either way it is counted.
     * `visit_prompt_declined` — they were asked and said they did not go. That is a human
     * contradicting the coordinates, and the human wins. Silently overwriting a "no" with a
     * detection would be the product telling somebody they are wrong about their own day.
     */
    private function alreadySettled(string $recommendationId): bool
    {
        return DB::table('recommendation_feedback')
            ->where('recommendation_id', $recommendationId)
            ->whereIn('event', [
                FeedbackEvent::Visited->value,
                FeedbackEvent::VisitPromptDeclined->value,
            ])
            ->exists();
    }
}
