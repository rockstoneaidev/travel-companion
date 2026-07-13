<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Actions;

use Illuminate\Support\Facades\DB;

/**
 * Detach the person from the money (docs/COST.md §10, ROPA).
 *
 * A per-user cost log is behavioural personal data — a timestamped record of when
 * someone used the app and how hard, which is arguably more revealing than the
 * recommendations it paid for. It needs a retention period like everything else.
 *
 * But it cannot simply be DELETED on the same schedule, because the money is also our
 * accounting, and "a user asked to be forgotten" must never mean "a month of spend
 * vanished from the P&L". So the columns that identify a person are nulled and the
 * financial columns are left exactly where they are. After this runs, the row still
 * says "$0.0006 of flash-lite was spent at 14:32 on the 3rd"; it no longer says by
 * whom, from where, or on which trip.
 *
 * This is the same shape as the trace coarsening next door (CoarsenExpiredTraces):
 * keeping the decision does not require keeping the person.
 *
 * Called on a schedule for ageing rows, and immediately for an erasure request —
 * which is why `cost_events` deliberately has NO foreign key to `users`. A cascade
 * would have deleted the accounting, and the cascade is exactly what every other
 * user-scoped table in this schema relies on.
 */
final class DeidentifyCostEvents
{
    /** @return int rows de-identified */
    public function __invoke(?int $userId = null): int
    {
        $query = DB::table('cost_events')
            ->whereNotNull('user_id');

        if ($userId !== null) {
            // Erasure: this person, now, regardless of age.
            $query->where('user_id', $userId);
        } else {
            // The scheduled pass: everyone, once their rows are old enough.
            $days = (int) config('cost.deidentify_after_days');
            $query->where('occurred_at', '<', now()->subDays($days));
        }

        return $query->update([
            'user_id' => null,
            'trip_id' => null,
            'session_id' => null,
            'recommendation_id' => null,
            'opportunity_id' => null,
            'conversation_id' => null,
            // The H3 cell is a ~460m hex — coarse, but a *location* nonetheless, and a
            // location attached to a timestamp is a movement record. It goes with the ids.
            'h3_cell' => null,
        ]);
    }
}
