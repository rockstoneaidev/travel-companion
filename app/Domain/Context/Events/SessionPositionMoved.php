<?php

declare(strict_types=1);

namespace App\Domain\Context\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The user's live position moved (E48 follow-up).
 *
 * Session START already asks "do we know this area?" (ExploreSessionStarted →
 * LearnAreaOnSessionStart). But a session does not stay where it started: the traveller
 * walks, the emulator drags the pin, and the feed re-anchors — and none of that was asking
 * the question again. So moving OUT of the ingested area found nothing and, worse, kicked
 * off no ingest of the new ground, because the only trigger was the door the user had
 * already walked through.
 *
 * This is that trigger, for every move after the first. Carries the MOVED-TO coordinate,
 * not the session's origin — the origin is where the session started and never moves; the
 * question is about where the person is NOW.
 *
 * Home-zone positions never dispatch it (RecordContextEvent suppresses the coordinate
 * first), so "learn the area around me" can never mean "learn the area around my home".
 */
final class SessionPositionMoved
{
    use Dispatchable;

    public function __construct(
        public readonly string $exploreSessionId,
        public readonly float $lat,
        public readonly float $lng,
    ) {}
}
