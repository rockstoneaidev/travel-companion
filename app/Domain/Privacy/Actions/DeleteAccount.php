<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Erasure (GDPR Art. 17, PRD §16). The strongest request a user can make, and the
 * one most likely to be quietly half-implemented.
 *
 * Everything user-scoped cascades from `users` at the FK level, which is why this
 * is short — but "the FKs handle it" is exactly the kind of claim that is true
 * until someone adds a table. So the accompanying test enumerates every table
 * with a user_id, deletes an account, and asserts each one is empty. It will fail
 * the day a new table forgets to cascade, which is the day you want to know.
 *
 * The feedback ledger goes too. It is the moat, and losing it hurts — but it is
 * THEIR moat, and "delete my account" is not a negotiation.
 *
 * ===========================================================================
 *  THE TWO TABLES THE CASCADE CANNOT REACH (ROPA §7.2, findings B1/B7)
 * ===========================================================================
 *
 * `activity_log` and `pulse_entries` hold personal data and have NO `user_id`
 * column — the first keys users through a polymorphic causer/subject morph, the
 * second stores the user id inside a string `key`. So no foreign key cascades to
 * them, and — worse — the test that guards this file could not see them either,
 * because it enumerates `information_schema` for columns literally named
 * `user_id`. A good test, honestly described, and blind in exactly the place the
 * bug was.
 *
 * They are deleted explicitly here, and the test now asserts it.
 *
 * A note on the audit trail, because there is a real tension and it should be
 * decided out loud rather than by omission: erasing `activity_log` rows means an
 * admin's record of "I granted this person a role" disappears when that person
 * leaves. Keeping them would need a lawful basis of its own — a retention
 * justification we have not made and, at three pilot users, could not honestly
 * make. The content is role grants, not security-incident evidence. So they go.
 * If this ever becomes a real audit obligation, the answer is a separate,
 * justified, minimised audit store — not quietly ignoring an erasure request.
 */
final class DeleteAccount
{
    public function __invoke(User $user): void
    {
        $id = $user->id;

        DB::transaction(function () use ($user, $id): void {
            $this->forgetActivityLog($id);
            $this->forgetTelemetry($id);

            $user->delete();
        });

        // Logged as an EVENT, not with the data: an audit trail that preserves what
        // the user asked us to forget is not an audit trail, it is a copy.
        Log::info('account deleted', [
            'user_id' => $id,
            'policy_version' => config('privacy.version'),
        ]);
    }

    /**
     * Both ends of the morph.
     *
     * SUBJECT is the obvious one — "a role was granted to this person" is personal
     * data about them. CAUSER is the one people forget: when the leaving account is
     * an admin, "this person granted a role" is personal data about *them*, and the
     * row survives their erasure while naming them.
     */
    private function forgetActivityLog(int $userId): void
    {
        $user = User::class;

        DB::table('activity_log')
            ->where(fn ($q) => $q->where('subject_type', $user)->where('subject_id', $userId))
            ->orWhere(fn ($q) => $q->where('causer_type', $user)->where('causer_id', $userId))
            ->delete();
    }

    /**
     * Pulse keys its per-user rows by the user id, as a STRING, in `key`.
     *
     * Scoped by `type` on purpose: `key` holds SQL for slow queries and URLs for
     * outgoing requests, and a bare `where('key', $id)` across every type would
     * eventually delete an unrelated row whose key happened to be "7".
     *
     * Pulse trims itself after 7 days, so this is a lag rather than a leak — but
     * "your data is gone, apart from the bit that expires next Tuesday" is not what
     * the notice says, and the notice is the promise.
     */
    private function forgetTelemetry(int $userId): void
    {
        foreach (['pulse_entries', 'pulse_aggregates'] as $table) {
            DB::table($table)
                ->whereIn('type', ['user_request', 'user_job', 'slow_user_request'])
                ->where('key', (string) $userId)
                ->delete();
        }
    }
}
