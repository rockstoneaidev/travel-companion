<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Carbon\CarbonImmutable;

/**
 * When the tap happened, as opposed to when we heard about it (SCREENS S11).
 *
 * Feedback queued in a dead zone is flushed on reconnect, sometimes hours later.
 * Stamping the flush time would put a falsehood in the moat (PRD §14.5) — and
 * PendingVisitPrompts reasons about *elapsed* time since "Take me", so it would
 * be a falsehood with consequences.
 *
 * The client is not trusted with it, though. A clock can be wrong, or lying:
 * a future timestamp would make a recommendation look accepted before it was
 * served, and an ancient one would resurrect a dead trip. So it is clamped into
 * a window, and anything outside it falls back to now.
 */
trait ResolvesFeedbackTime
{
    /** How far back a queued tap may plausibly have come from (offline, then reconnect). */
    private const MAX_QUEUE_AGE_DAYS = 7;

    protected function occurredAt(?string $clientTime): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        if ($clientTime === null) {
            return $now;
        }

        try {
            $at = CarbonImmutable::parse($clientTime);
        } catch (\Throwable) {
            return $now;
        }

        // The future is not a time a tap can have happened in.
        if ($at->isAfter($now)) {
            return $now;
        }

        return $at->isBefore($now->subDays(self::MAX_QUEUE_AGE_DAYS)) ? $now : $at;
    }
}
