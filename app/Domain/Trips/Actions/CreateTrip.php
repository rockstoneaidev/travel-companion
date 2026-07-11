<?php

declare(strict_types=1);

namespace App\Domain\Trips\Actions;

use App\Domain\Trips\Data\NewTripData;
use App\Domain\Trips\Enums\TripSource;
use App\Domain\Trips\Enums\TripStatus;
use App\Domain\Trips\Models\Trip;

/**
 * `POST /api/v1/trips` — the OPTIONAL planner path (PRD §6.6/§14.5). It opens a
 * `planned` trip, never an `active` one: "active" begins at the first session,
 * and there is at most one active trip per user.
 *
 * A planned trip is not yet joinable by the implicit clustering — that only ever
 * looks at the live trip. Making the planner's trip absorb the first session in
 * its region is a real product decision and it belongs to the pre-scouting epic,
 * not here.
 */
final class CreateTrip
{
    public function __invoke(NewTripData $data): Trip
    {
        return Trip::query()->create([
            'user_id' => $data->userId,
            'name' => $data->name,
            'status' => TripStatus::Planned,
            'source' => TripSource::User,
            'anchor_point' => $data->anchorPoint,
            'clustering_version' => config('trips.clustering.version'),
        ]);
    }
}
