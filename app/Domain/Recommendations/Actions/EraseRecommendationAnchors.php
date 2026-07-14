<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Actions;

use App\Domain\Recommendations\Contracts\RecommendationTraceEraser;
use Illuminate\Support\Facades\DB;

/**
 * "Delete all raw location history for this trip" — the trace half (PRD §16, §14.5).
 *
 * Note what this does NOT do: delete the recommendation. The trace stays, because it
 * is how "why did I get this?" is answered and what the replayer replays (§15.2), and
 * because the user asked to erase where they WERE, not what they were shown.
 *
 * What goes is every coordinate the trip's traces hold:
 *
 *  - `anchor` — where we ranked the batch from. That is a place the user stood.
 *  - `anchor_h3_index` — the coarse cell. Also erased, and deliberately: on-demand
 *    trip deletion removes raw *and derived* location data (§16), unlike the 30-day
 *    retention job, whose whole purpose is to KEEP the cell and drop the coordinate.
 *    Same two columns, opposite intents; conflating them would quietly turn a
 *    deletion request into a coarsening.
 *  - the candidate lat/lng inside `score_inputs` — the geography of a place we told
 *    you about while you were standing next to it, which locates you just as well as
 *    the anchor does. `CoarsenExpiredTraces` already treats these as personal data;
 *    an erasure that left them behind would be one that did not erase.
 */
final class EraseRecommendationAnchors implements RecommendationTraceEraser
{
    public function eraseForTrip(string $tripId): int
    {
        return DB::update(
            "UPDATE recommendations
                SET anchor = NULL,
                    anchor_h3_index = NULL,
                    score_inputs = jsonb_set(
                        score_inputs,
                        '{candidate}',
                        (score_inputs -> 'candidate') - 'lat' - 'lng'
                    )
              WHERE trip_id = ?
                AND (
                    anchor IS NOT NULL
                    OR anchor_h3_index IS NOT NULL
                    -- jsonb_exists(), NOT the `?` operator: PDO binds `?` as a
                    -- placeholder and the statement dies on argument count.
                    OR jsonb_exists(score_inputs -> 'candidate', 'lat')
                )",
            [$tripId],
        );
    }
}
