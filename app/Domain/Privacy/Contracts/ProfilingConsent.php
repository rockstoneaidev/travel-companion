<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Contracts;

/**
 * May we learn a taste profile for this person? (GDPR Art. 9(2)(a), DPIA §3.2)
 *
 * A contract rather than a service call, because Profiles must be able to ask
 * without reaching into Privacy's models (conventions/01) — and because the answer
 * has to be enforceable at the ONE place where learning actually happens, so that
 * adding a new caller later cannot quietly bypass it.
 */
interface ProfilingConsent
{
    public function granted(int $userId): bool;

    /**
     * Have we ever ASKED? A different fact from whether they said yes.
     *
     * Conflating the two is what caused an infinite redirect: /welcome sent a user
     * who had already finished calibration on to /explore, and the ask-once
     * middleware sent them straight back, because "has not consented" and "has not
     * been asked" looked identical.
     */
    public function asked(int $userId): bool;
}
