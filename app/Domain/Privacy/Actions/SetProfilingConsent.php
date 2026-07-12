<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Actions;

use App\Domain\Profiles\Contracts\TasteProfileEraser;
use Illuminate\Support\Facades\DB;

/**
 * Give, or take back, explicit consent to be profiled (Art. 9(2)(a), Art. 7(3)).
 *
 * WITHDRAWAL DELETES THE PROFILE, and that is a deliberate legal reading rather
 * than a UX flourish.
 *
 * Withdrawing consent removes the basis for the processing — and *holding* a vector
 * from which someone's religious belief can be deduced is itself processing. So
 * "stop learning but keep what you inferred" would leave us storing Art. 9 data
 * with no lawful basis at all, which is a worse position than never having asked.
 *
 * It must also be as easy to withdraw as it was to give (Art. 7(3)): one click, no
 * password, no dark pattern, no "are you sure you want to lose your personalised
 * experience". The service keeps working — it falls back to the honest cold-start
 * ranking, which is exactly what that fallback is for (SCORING §6).
 */
final class SetProfilingConsent
{
    public function __construct(private readonly TasteProfileEraser $profiles) {}

    public function grant(int $userId): void
    {
        DB::table('users')->where('id', $userId)->update([
            'profiling_consent_at' => now(),
            'profiling_consent_version' => config('privacy.profiling_consent_version'),
        ]);
    }

    public function withdraw(int $userId): void
    {
        DB::transaction(function () use ($userId): void {
            DB::table('users')->where('id', $userId)->update([
                'profiling_consent_at' => null,
                'profiling_consent_version' => null,
            ]);

            // The conclusions go with the permission to have drawn them.
            $this->profiles->eraseForUser($userId);
        });
    }
}
