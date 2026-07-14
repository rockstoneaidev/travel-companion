<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Sources\Actions\LearnAreaIfUnknown;
use App\Domain\Trips\Contracts\ExploreSessionLookup;
use App\Domain\Trips\Events\ExploreSessionStarted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Someone opened a session somewhere we have never heard of — go and learn it (E48).
 *
 * A LISTENER, not a line in `StartExploreSession`, and the event's own docblock asked for
 * exactly this: *"The scouts listen to this — everything interesting happens because of
 * events, not synchronous API requests."* Trips may not call a Sources action anyway
 * (conventions/01), and the event has been dispatched since E4 with nothing listening.
 *
 * QUEUED, because it reverse-geocodes over the network and then fans out an hour of
 * Overpass. A traveller pressing "I have 3 hours" must not wait on Nominatim to find out
 * whether we have heard of the town they are standing in.
 */
final class LearnAreaOnSessionStart implements ShouldQueue
{
    public function __construct(
        private readonly ExploreSessionLookup $sessions,
        private readonly LearnAreaIfUnknown $learn,
    ) {}

    public function handle(ExploreSessionStarted $event): void
    {
        $session = $this->sessions->find($event->exploreSessionId);

        if ($session === null || $session->origin === null) {
            return;
        }

        /*
         * Reach, not a fixed radius: "do we know this area" has to mean "do we know
         * anywhere this person could actually GET to". A driver with three hours has a
         * 40 km question and a walker has a 3 km one, and answering the walker's question
         * for the driver would leave them staring at an empty feed inside a region we
         * technically "have".
         */
        ($this->learn)(
            $session->origin->lat,
            $session->origin->lng,
            $session->reachMeters(),
            $session->userId,
        );
    }

    /**
     * A region we failed to learn is a disappointment, not an outage.
     *
     * The session is already open and the feed already works (it is simply empty, and now
     * says so honestly). Nothing about this listener is worth failing a job over, so it
     * swallows and logs rather than retrying an Overpass fan-out on a timer.
     */
    public function failed(ExploreSessionStarted $event, Throwable $e): void
    {
        report($e);
    }
}
