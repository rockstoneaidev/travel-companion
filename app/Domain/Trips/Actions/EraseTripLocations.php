<?php

declare(strict_types=1);

namespace App\Domain\Trips\Actions;

use App\Domain\Trips\Contracts\TripLocationEraser;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use Illuminate\Support\Facades\DB;

/**
 * Hard-deletes the raw coordinates Trips holds for a trip (PRD §16). The rows
 * survive — a session with no origin is still a session, and the trip is still
 * in the user's list — but the precise location is gone, immediately, not
 * scheduled for coarsening.
 *
 * Consequence, and it is intended: a trip whose anchor is erased no longer
 * absorbs new sessions (see ResolveOrCreateTripForSession).
 */
final class EraseTripLocations implements TripLocationEraser
{
    public function eraseForTrip(string $tripId): int
    {
        return DB::transaction(function () use ($tripId): int {
            $sessions = ExploreSession::query()
                ->where('trip_id', $tripId)
                ->update([
                    'origin' => null,
                    'destination_point' => null,
                    'origin_h3_index' => null,
                ]);

            $trips = Trip::query()
                ->whereKey($tripId)
                ->update(['anchor_point' => null]);

            return $sessions + $trips;
        });
    }
}
