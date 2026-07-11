<?php

declare(strict_types=1);

namespace App\Domain\Context\Actions;

use App\Domain\Context\Data\NewContextEventData;
use App\Domain\Context\Exceptions\ExploreSessionNotAcceptingEvents;
use App\Domain\Context\Models\ContextEvent;
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
    public function __construct(private readonly ExploreSessionLookup $sessions) {}

    public function __invoke(NewContextEventData $data): ContextEvent
    {
        $session = $this->sessions->find($data->exploreSessionId);

        if ($session === null || ! $session->isLive()) {
            throw new ExploreSessionNotAcceptingEvents($data->exploreSessionId);
        }

        return ContextEvent::query()->create([
            'explore_session_id' => $session->id,
            'trip_id' => $session->tripId,          // denormalised: the privacy erase scans by trip
            'user_id' => $session->userId,
            'occurred_at' => $data->occurredAt(),
            'location' => $data->location,
            'accuracy_meters' => $data->accuracyMeters,
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
