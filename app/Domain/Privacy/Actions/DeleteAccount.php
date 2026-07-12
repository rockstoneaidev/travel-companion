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
 */
final class DeleteAccount
{
    public function __invoke(User $user): void
    {
        $id = $user->id;

        DB::transaction(function () use ($user): void {
            $user->delete();
        });

        // Logged as an EVENT, not with the data: an audit trail that preserves what
        // the user asked us to forget is not an audit trail, it is a copy.
        Log::info('account deleted', [
            'user_id' => $id,
            'policy_version' => config('privacy.version'),
        ]);
    }
}
