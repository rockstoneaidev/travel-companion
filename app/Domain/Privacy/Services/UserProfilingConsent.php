<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Services;

use App\Domain\Privacy\Contracts\ProfilingConsent;
use Illuminate\Support\Facades\DB;

/**
 * Consent, as stored (DPIA §3.2).
 *
 * Granted only if the recorded version still matches the CURRENT one: if we widen
 * what the profile infers, the old agreement does not cover the new thing, and a
 * consent that silently stretches to cover whatever we build next is not consent.
 */
final class UserProfilingConsent implements ProfilingConsent
{
    public function granted(int $userId): bool
    {
        $row = DB::table('users')
            ->where('id', $userId)
            ->first(['profiling_consent_at', 'profiling_consent_version']);

        return $row?->profiling_consent_at !== null
            && $row->profiling_consent_version === config('privacy.profiling_consent_version');
    }
}
