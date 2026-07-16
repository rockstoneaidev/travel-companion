<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Context\Events\SessionPositionMoved;
use App\Domain\Sources\Actions\LearnAreaIfUnknown;
use App\Domain\Trips\Contracts\ExploreSessionLookup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The traveller moved somewhere new — check whether we know it, and learn it if not (E48).
 *
 * The twin of {@see LearnAreaOnSessionStart}, for every move after the first. It learns the
 * MOVED-TO position (the event carries it), using the session's reach so "do we know here"
 * means "do we know anywhere this person could actually get to from here".
 *
 * QUEUED and THROTTLED. Queued because it may fan out an hour of Overpass and must never sit
 * on the context-event write path. Throttled because moves are frequent — a phone reporting
 * meaningful changes, an operator dragging a pin — and re-asking "do we know this
 * neighbourhood" a hundred times a minute for the same neighbourhood is waste. One check per
 * coarse patch of ground per user per couple of minutes is plenty; the actual guards
 * (already-covered, has-places, the per-day cap, the build lock) do the rest.
 */
final class LearnAreaOnPositionMoved implements ShouldQueue
{
    /** ~0.01° ≈ one res-8 tile; a move within it is the same "here". */
    private const THROTTLE_PRECISION = 3;

    private const THROTTLE_SECONDS = 120;

    public function __construct(
        private readonly ExploreSessionLookup $sessions,
        private readonly LearnAreaIfUnknown $learn,
    ) {}

    public function handle(SessionPositionMoved $event): void
    {
        $session = $this->sessions->find($event->exploreSessionId);

        if ($session === null) {
            return;
        }

        // One check per patch of ground per user per throttle window. Cache::add is the
        // atomic "claim it if nobody else has" — a burst of moves in one spot collapses to
        // a single check.
        $key = sprintf(
            'learn-move:%d:%.'.self::THROTTLE_PRECISION.'f:%.'.self::THROTTLE_PRECISION.'f',
            $session->userId,
            $event->lat,
            $event->lng,
        );

        if (! Cache::add($key, true, self::THROTTLE_SECONDS)) {
            return;
        }

        ($this->learn)($event->lat, $event->lng, $session->reachMeters(), $session->userId);
    }

    public function failed(SessionPositionMoved $event, Throwable $e): void
    {
        // A region we failed to learn is a disappointment, not an outage (the feed still
        // works, empty and honest). Swallow rather than retry an Overpass fan-out on a timer.
        report($e);
    }
}
