<?php

declare(strict_types=1);

namespace App\Domain\Trips\Actions;

use App\Domain\Trips\Data\TripUpdateData;
use App\Domain\Trips\Enums\TripStatus;
use App\Domain\Trips\Models\Trip;
use Carbon\CarbonImmutable;

/**
 * `PATCH /api/v1/trips/{trip}` — rename, mark ended (PRD §14.5).
 *
 * The only status transition a client may ask for is → `completed`. Reopening a
 * trip would race the one-active-trip-per-user invariant and there is no product
 * reason for it: starting a session near a completed trip does not reopen it,
 * it opens a new one (that is the clustering's decision to make, not the
 * client's).
 */
final class UpdateTrip
{
    public function __invoke(Trip $trip, TripUpdateData $data): Trip
    {
        if ($data->name !== null) {
            $trip->name = $data->name;
        }

        if ($data->complete && $trip->status !== TripStatus::Completed) {
            $trip->status = TripStatus::Completed;
            $trip->ended_at = CarbonImmutable::now();
        }

        $trip->save();

        return $trip;
    }
}
