<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Actions;

use App\Domain\Profiles\Models\ProfileSignal;
use App\Domain\Profiles\Models\UserTasteProfile;
use Illuminate\Support\Facades\DB;

/**
 * "Reset my taste profile" (SCREENS S10).
 *
 * The user is telling us we have them wrong. Taking that seriously means going
 * back to knowing nothing — α returns to 0, the cold-start vector takes over
 * again, and they get honest "I don't know you yet" ranking instead of a
 * confident wrong one.
 *
 * What this deletes and what it does NOT:
 *
 *   - The LEARNED profile goes: facet weights, event counts, the calibration
 *     stamp, the practicals. That is the thing they are complaining about.
 *   - The FEEDBACK LEDGER stays. It is the moat (PRD §14.5) and it is a record of
 *     what actually happened — the user tapped those things, and un-tapping
 *     history is not what they asked for. It also means a future profile_model
 *     version can rebuild a better profile from the same events, which is the
 *     whole reason the ledger is append-only.
 *
 * So this is "forget what you concluded about me", not "forget what I did". The
 * second one is account deletion, and it lives in Privacy (E17, PRD §16).
 */
final class ResetTasteProfile
{
    public function __invoke(int $userId): void
    {
        DB::transaction(function () use ($userId): void {
            // Calibration answers go too: they are inputs to the profile, and
            // leaving them would let the user re-take a calibration whose pairs are
            // already answered — the flow would resume at the end and teach nothing.
            ProfileSignal::query()->where('user_id', $userId)->delete();

            UserTasteProfile::query()->where('user_id', $userId)->delete();

            // Recreated with the documented defaults rather than left absent, so the
            // very next scorer read gets tolerance 15 / band 2 and not a zeroed
            // profile that maxes friction on every candidate.
            UserTasteProfile::for($userId);
        });
    }
}
