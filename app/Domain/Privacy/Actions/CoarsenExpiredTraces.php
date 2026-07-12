<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Actions;

use App\Domain\Privacy\Data\RetentionReport;
use Illuminate\Support\Facades\DB;

/**
 * Coarsen the location fields of old recommendation traces (PRD §16, §15).
 *
 * Traces are kept INDEFINITELY, and that is deliberate: a trace is how we answer
 * "why did I get this suggestion?", and the replayer needs them to tell whether a
 * pipeline change altered what we serve (§15.2). Keeping the decision does not
 * require keeping the coordinates, though — so the lat/lng inside `score_inputs`
 * are dropped on the same 30-day schedule, and the H3 cell (already in the
 * snapshot) carries the geography from then on.
 *
 * EXCEPT for accounts with explicit research consent, whose full-precision traces
 * feed the gold-trace suite. That exemption is the sharp edge of this file: get
 * the predicate backwards and you silently retain precise location on every user
 * who never opted in, which is the exact failure this whole epic exists to
 * prevent. It is opt-in, it is off by default, and there is a test asserting a
 * non-consenting user's trace IS coarsened.
 */
final class CoarsenExpiredTraces
{
    public function __invoke(): RetentionReport
    {
        $days = (int) config('privacy.trace_location_retention_days');
        $cutoff = now()->subDays($days);

        /*
         * Strip lat/lng from the candidate snapshot, in the database, leaving the
         * rest of the trace (name, type, facets, h3_index, scouts) untouched — that
         * is what the replayer reads.
         *
         * `NOT users.research_consent` is the whole exemption. A consenting account
         * keeps its precise traces; everyone else's are coarsened, on schedule,
         * whether or not anyone remembers this file exists.
         */
        $traces = DB::update(
            "UPDATE recommendations r
                SET score_inputs = jsonb_set(
                        r.score_inputs,
                        '{candidate}',
                        (r.score_inputs -> 'candidate') - 'lat' - 'lng'
                    )
              FROM users u
             WHERE u.id = r.user_id
               AND NOT u.research_consent
               AND r.served_at < ?
               -- jsonb_exists(), NOT the `?` key-exists operator: PDO would bind it
               -- as a placeholder and the statement would fail on argument count.
               AND jsonb_exists(r.score_inputs -> 'candidate', 'lat')",
            [$cutoff],
        );

        return new RetentionReport(contextEvents: 0, sessions: 0, trips: 0, traces: $traces);
    }
}
