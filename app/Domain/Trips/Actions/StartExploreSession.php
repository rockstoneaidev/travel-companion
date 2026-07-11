<?php

declare(strict_types=1);

namespace App\Domain\Trips\Actions;

use App\Domain\Trips\Data\NewExploreSessionData;
use App\Domain\Trips\Enums\ExploreSessionStatus;
use App\Domain\Trips\Events\ExploreSessionStarted;
use App\Domain\Trips\Models\ExploreSession;
use Illuminate\Support\Facades\DB;

/**
 * `POST /api/v1/explore-sessions` — the one thing the user initiates (PRD §6.6).
 *
 * The session is top-level; the trip is resolved-or-created behind it. Both the
 * Inertia controller and the API controller call this, and nothing else creates
 * a session.
 */
final class StartExploreSession
{
    public function __construct(private readonly ResolveOrCreateTripForSession $resolveTrip) {}

    public function __invoke(NewExploreSessionData $data): ExploreSession
    {
        return DB::transaction(function () use ($data): ExploreSession {
            $startedAt = $data->startedAt();

            $trip = ($this->resolveTrip)($data->userId, $data->origin, $startedAt);

            $session = ExploreSession::query()->create([
                'user_id' => $data->userId,
                'trip_id' => $trip->id,
                'origin' => $data->origin,
                'time_budget_minutes' => $data->timeBudgetMinutes,
                'travel_mode' => $data->travelMode,
                'heading' => $data->heading,
                'destination_point' => $data->destinationPoint,
                'status' => ExploreSessionStatus::Active,
                'started_at' => $startedAt,
                'expires_at' => $startedAt->addMinutes($data->timeBudgetMinutes),
            ]);

            // PRD §10's event vocabulary. Nothing listens yet: the scouts that
            // will (E5) don't exist. Emitting from day one is what makes them
            // additive rather than a rewrite.
            ExploreSessionStarted::dispatch($session->id, $trip->id, $data->userId);

            return $session;
        });
    }
}
