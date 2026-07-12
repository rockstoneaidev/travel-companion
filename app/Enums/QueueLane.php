<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The queue lanes (conventions/08).
 *
 * Lanes are separated by the SHAPE of the work, not by the feature that queued
 * it. What matters to a worker is: how long does this run, how bad is it if it
 * waits, and how bad is it if it runs twice.
 *
 * Everything used to share one `default` lane, and that is what broke the world
 * model build on staging: a fifteen-minute Dijon ingest sat in the same lane as
 * everything else, and Laravel's `retry_after` — which is per CONNECTION, not per
 * queue — was 90 seconds. After 90s the queue decided the job was dead and handed
 * it to a second worker while the first was still running. Attempts hit 2 against
 * `tries = 1` and it failed as MaxAttemptsExceeded, having actually been running
 * fine the whole time.
 *
 * You cannot serve a 30-second job and a 15-minute job from one connection. That
 * is the whole reason this enum exists.
 */
enum QueueLane: string
{
    use HasOptions;

    /**
     * Someone is waiting. Push notifications, broadcasts.
     *
     * Dormant until Phase 2 (no push, no Reverb in Phase 1 — PRD §8). The lane
     * exists now so that when it lands it does not get dumped into `default`,
     * behind a world-model build, which is precisely the bug above.
     */
    case Realtime = 'realtime';

    /** Short work off the back of a request: feedback, taste updates, session close. */
    case Default = 'default';

    /** LLM generations. Seconds to a minute, retryable, must never block a feed. */
    case Voice = 'voice';

    /** Tile warming. Thousands of tiny DB jobs; wide, short, cheap. */
    case Scouts = 'scouts';

    /**
     * World-model builds. Minutes, sometimes many.
     *
     * Runs on its own CONNECTION (`redis-long`) because retry_after is a property
     * of the connection — this lane needs a long one, and forcing a long
     * retry_after on the realtime lane would mean a genuinely dead push takes half
     * an hour to be retried.
     *
     * Also deliberately SERIAL (one process). Not for tidiness: public Overpass
     * returned 504s when we ran the corridor cities back to back, and running two
     * region ingests at once is how you get rate-limited off a source you do not
     * pay for.
     */
    case Ingest = 'ingest';

    /** The Laravel queue connection this lane is served from. */
    public function connection(): string
    {
        return match ($this) {
            self::Ingest => 'redis-long',
            default => 'redis',
        };
    }
}
