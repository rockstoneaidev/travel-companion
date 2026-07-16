<?php

declare(strict_types=1);

namespace App\Domain\Context\Actions;

use App\Domain\Context\Data\NewContextEventData;
use App\Domain\Context\Events\SessionPositionMoved;
use App\Domain\Context\Exceptions\ExploreSessionNotAcceptingEvents;
use App\Domain\Context\Models\ContextEvent;
use App\Domain\Places\Contracts\TileIndexer;
use App\Domain\Privacy\Services\HomeZone;
use App\Domain\Trips\Contracts\ExploreSessionLookup;

/**
 * `POST /api/v1/explore-sessions/{session}/context-events` (PRD §14.5).
 *
 * Context events are session-scoped (PRD §6.6), so the rule that a *dead*
 * session stops collecting lives here — not in a controller, and not in an `if`
 * the mobile client would have to reimplement.
 *
 * Trips is reached through its published contract; Context never touches an
 * ExploreSession model (conventions/01).
 */
final class RecordContextEvent
{
    public function __construct(
        private readonly ExploreSessionLookup $sessions,
        private readonly TileIndexer $tiles,
    ) {}

    public function __invoke(NewContextEventData $data): ContextEvent
    {
        $session = $this->sessions->find($data->exploreSessionId);

        if ($session === null || ! $session->isLive()) {
            throw new ExploreSessionNotAcceptingEvents($data->exploreSessionId);
        }

        /*
         * Sensitive-zone suppression, the half that cannot be undone (PRD §16).
         *
         * Inside the declared home zone we keep the H3 cell — coarse presence, which
         * is what the pipeline actually needs — and NEVER the coordinates. Not for
         * thirty days, not for thirty seconds. The retention job can coarsen a
         * coordinate later; it cannot un-store one that was written, and "we'll
         * delete it on schedule" is not the same promise as "we never had it".
         */
        $location = $data->location;
        $accuracy = $data->accuracyMeters;
        $cell = null;

        if ($location !== null) {
            $cell = $this->tiles->cellFor($location->lat, $location->lng);

            if (HomeZone::forUser($session->userId)->contains($location->lat, $location->lng)) {
                $location = null;
                $accuracy = null;   // the accuracy of a coordinate we did not keep is not a fact about anything
            }
        }

        /*
         * The traveller moved — ask whether we know where they now are, and learn it if not
         * (E48). Session start already asks this once; without this, walking OUT of the
         * ingested area found nothing AND started no ingest of the new ground, because the
         * only trigger was the door already walked through.
         *
         * Fired only when a real coordinate was kept — a home-zone position is suppressed to
         * null above, so "learn the area around me" can never become "learn my home". Queued
         * and throttled downstream; it never touches this write path.
         */
        if ($location !== null) {
            SessionPositionMoved::dispatch($session->id, $location->lat, $location->lng);
        }

        return ContextEvent::query()->create([
            'explore_session_id' => $session->id,
            'trip_id' => $session->tripId,          // denormalised: the privacy erase scans by trip
            'user_id' => $session->userId,
            /*
             * Inherited from the SESSION, never read off the request (ADMIN §6).
             *
             * A client cannot claim to be emulated and a real phone cannot be mislabelled
             * as one, because there is no field in the payload to say it with. "Is this
             * real?" must not be a question the caller answers about itself.
             */
            'context_source' => $session->contextSource,
            'occurred_at' => $data->occurredAt(),
            'location' => $location,
            'h3_index' => $cell,
            'accuracy_meters' => $accuracy,
            'movement_mode' => $data->movementMode,
            'speed_mps' => $data->speedMps,
            'heading' => $data->heading,
            'app_state' => $data->appState,
            'battery_level' => $data->batteryLevel,
            'is_low_power_mode' => $data->isLowPowerMode,
            'available_minutes' => $data->availableMinutes,
            'companions' => $data->companions,
        ]);
    }
}
