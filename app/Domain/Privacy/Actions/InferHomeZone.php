<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Actions;

use App\Domain\Privacy\Models\InferredHomeZone;
use App\Domain\Privacy\Services\HomeZone;
use Illuminate\Support\Facades\DB;

/**
 * "Where does this person sleep?" — inferred, coarsely, and only ever proposed (E40).
 *
 * ## The signal: the ends of the day, not the middle of the night
 *
 * The obvious way to find home is "where are they at 3am", and it is the wrong way, because
 * we do not reliably know a traveller's timezone — that is rather the point of a travel app.
 * A UTC night window would call a Californian's afternoon "night".
 *
 * The signal that needs no clock is the SHAPE of a day: wherever you are for the FIRST and
 * LAST event of each day is where you slept, in any timezone, on any continent. Home is the
 * cell that is a day-boundary across the most distinct days. A hotel scores this for a week
 * and then stops; a home scores it for months. The `nights_observed` threshold is what tells
 * them apart.
 *
 * ## What it will not do
 *
 * - It will not run if a home zone is already declared. That question is answered.
 * - It stores a CELL, never a coordinate (the migration explains why this is load-bearing).
 * - It only ever PROPOSES. Suppression is opted into, never assumed — an inferred zone that
 *   activated itself would silently blind the product to a neighbourhood the user wanted.
 */
final class InferHomeZone
{
    public const VERSION = 'v1';

    public function __invoke(int $userId): ?InferredHomeZone
    {
        // A declared zone is the user's own word, and it wins. Nothing to infer.
        if (HomeZone::forUser($userId)->declared()) {
            return null;
        }

        $config = config('privacy.home_inference');

        /*
         * For each distinct day, the cell of the first and last event — where the day began
         * and ended. Then: which cell is a day-boundary across the most DISTINCT days?
         *
         * Distinct days, not distinct events, is the whole robustness of it: a single
         * insomniac night pinging home fifty times counts once, and a week in one hotel
         * cannot outweigh months in one flat.
         */
        $rows = DB::select(<<<'SQL'
            WITH bounds AS (
                SELECT
                    (occurred_at AT TIME ZONE 'UTC')::date AS day,
                    FIRST_VALUE(h3_index) OVER w AS first_cell,
                    LAST_VALUE(h3_index)  OVER w AS last_cell
                FROM context_events
                WHERE user_id = ?
                  AND h3_index IS NOT NULL
                WINDOW w AS (
                    PARTITION BY (occurred_at AT TIME ZONE 'UTC')::date
                    ORDER BY occurred_at
                    ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING
                )
            ),
            day_cells AS (
                SELECT day, first_cell AS cell FROM bounds
                UNION
                SELECT day, last_cell  AS cell FROM bounds
            )
            SELECT cell, COUNT(DISTINCT day) AS nights
            FROM day_cells
            GROUP BY cell
            ORDER BY nights DESC
        SQL, [$userId]);

        if ($rows === []) {
            return null;
        }

        $top = $rows[0];
        $nights = (int) $top->nights;

        if ($nights < (int) $config['min_nights']) {
            return null;   // not enough of a pattern to call it home
        }

        // Dominance: home should stand clear of the runner-up. A person with two homes, or a
        // long trip split evenly between two bases, is genuinely ambiguous — and an ambiguous
        // home zone is one we should not propose, because suppressing the wrong one is worse
        // than suppressing neither.
        $runnerUp = isset($rows[1]) ? (int) $rows[1]->nights : 0;

        if ($nights < $runnerUp * (float) $config['dominance_ratio']) {
            return null;
        }

        $confidence = round(min(1.0, $nights / (float) $config['confident_at_nights']), 3);

        $zone = InferredHomeZone::query()->firstOrNew([
            'user_id' => $userId,
            'h3_index' => $top->cell,
        ]);

        // Re-affirming evidence updates the numbers but NEVER a decision the user already
        // made. If they rejected this cell (a hotel they liked), stronger evidence that they
        // slept there is not a reason to ask again — it is the reason they said no.
        if ($zone->exists && $zone->status !== 'proposed') {
            return $zone;
        }

        $zone->fill([
            'nights_observed' => $nights,
            'confidence' => $confidence,
            'inference_version' => self::VERSION,
            'status' => 'proposed',
        ])->save();

        return $zone;
    }
}
