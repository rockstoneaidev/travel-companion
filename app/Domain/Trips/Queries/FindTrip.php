<?php

declare(strict_types=1);

namespace App\Domain\Trips\Queries;

use App\Domain\Trips\Contracts\TripLookup;
use App\Domain\Trips\Data\TripData;
use App\Domain\Trips\Models\Trip;

final class FindTrip implements TripLookup
{
    public function find(string $tripId): ?TripData
    {
        $trip = Trip::query()->find($tripId);

        return $trip === null ? null : TripData::fromModel($trip);
    }
}
