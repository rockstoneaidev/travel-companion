<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Services;

use App\Domain\Context\Contracts\SessionPositions;
use App\Domain\Places\Data\Coordinates;
use App\Domain\Trips\Data\ExploreSessionData;
use Carbon\CarbonImmutable;

/**
 * Where should this session's feed be ranked from — and has that changed enough
 * to be worth re-ranking? (E46; PRD §8.1, §9.2.)
 *
 * The whole of "the feed follows you" is this one decision. Everything else in the
 * epic is plumbing: give RankSession a different origin and it produces a different
 * menu without knowing why.
 *
 * The bar is deliberately conservative. A feed that re-serves whenever the GPS
 * twitches is not responsive, it is unreadable — cards move under the reader's
 * thumb, and the item they were about to tap is gone. So we re-anchor on evidence
 * of TRAVEL, not evidence of noise.
 */
final class SessionAnchor
{
    public function __construct(
        private readonly SessionPositions $positions,
    ) {}

    /**
     * The position to rank from right now: the latest reported fix, or — when the
     * client has told us nothing (or nothing outside the home zone) — where the
     * session started.
     */
    public function current(ExploreSessionData $session): ?Coordinates
    {
        return $this->positions
            ->latestFor($session->id, (int) config('trips.reanchor.position_max_age_seconds'))
            ?->at
            ?? $session->origin;
    }

    /**
     * How long the feed must stand still before it may be re-served.
     *
     * This guard is about a HUMAN READING A SCREEN — cards must not move out from under
     * a thumb — and not about correctness. So an emulated session gets a much shorter
     * one, because there is no thumb: playback compresses a two-hour walk into two
     * minutes, and at 60× a 120-second hold means the pipeline reacts once and then
     * watches the pin cross the city in silence. That is exactly what it did on the
     * first walk anyone drove (2026-07-14), and it made the tool look broken when it was
     * merely being polite.
     *
     * The DRIFT threshold is not relaxed the same way, and must not be: "has this person
     * actually moved" is a question about the world, and the emulator is supposed to be
     * asking the real one.
     */
    public function minIntervalSeconds(ExploreSessionData $session): int
    {
        return (int) config(
            $session->contextSource->isReal()
                ? 'trips.reanchor.min_interval_seconds'
                : 'trips.reanchor.min_interval_seconds_emulated',
        );
    }

    /**
     * The new anchor if the user has moved far enough to deserve a fresh menu,
     * or null to leave the feed exactly where it is.
     *
     * @param  Coordinates|null  $servedFrom  the anchor of the batch currently on screen
     * @param  CarbonImmutable|null  $lastServedAt  when that batch was ranked
     * @param  int  $serveCount  batches served so far in this session
     * @param  bool  $recentlyDry  a recent re-serve found nothing — don't grind the pipeline again
     */
    public function driftedFrom(
        ExploreSessionData $session,
        ?Coordinates $servedFrom,
        ?CarbonImmutable $lastServedAt,
        int $serveCount,
        CarbonImmutable $at,
        bool $recentlyDry = false,
    ): ?Coordinates {
        if ($servedFrom === null || $recentlyDry) {
            return null;
        }

        // Cost and churn ceiling. Counts every serve, not just the automatic ones:
        // this is a bound on how many times a session may be ranked, full stop.
        if ($serveCount >= (int) config('trips.reanchor.max_serves_per_session')) {
            return null;
        }

        // Don't re-serve on top of a menu the user has barely had time to read.
        $minInterval = $this->minIntervalSeconds($session);

        if ($lastServedAt !== null && $lastServedAt->diffInSeconds($at, absolute: true) < $minInterval) {
            return null;
        }

        $fix = $this->positions->latestFor(
            $session->id,
            (int) config('trips.reanchor.position_max_age_seconds'),
        );

        if ($fix === null) {
            return null;
        }

        $drift = $servedFrom->distanceTo($fix->at);
        $threshold = (float) config('trips.reanchor.min_drift_meters');

        /*
         * Accuracy is part of the threshold, not a footnote.
         *
         * A fix that says "±600 m" is consistent with the user not having moved at
         * all, so a 450 m "drift" derived from it is not evidence of travel — it is
         * the error bar. Indoors and in dense city blocks that is the common case,
         * which is exactly where a traveller sits reading the feed. Requiring the
         * drift to exceed the device's own claimed error keeps a stationary phone
         * with a bad view of the sky from re-ranking itself in circles.
         */
        if ($fix->accuracyMeters !== null) {
            $threshold = max($threshold, (float) $fix->accuracyMeters);
        }

        return $drift >= $threshold ? $fix->at : null;
    }
}
