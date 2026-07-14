<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Trips\Actions\StartTripMode;
use App\Domain\Trips\Actions\StopTripMode;
use App\Domain\Trips\Models\Trip;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TripResource;

/**
 * `POST /api/v1/trips/{trip}/trip-mode/start` and `/stop` (PRD §14.5, E29).
 *
 * The switch that turns a pull-based app into a companion. Everything Phase 2 is allowed
 * to do — background location, geofences, interrupting somebody who is not looking at
 * their phone — is downstream of this returning 200.
 */
final class TripModeController extends Controller
{
    public function start(Trip $trip, StartTripMode $start): TripResource
    {
        return new TripResource($start($trip));
    }

    /**
     * Off. From any state, always.
     *
     * `StopTripMode` never throws, and the route is gated on ownership alone — no status
     * check, no "but the trip already ended". An off-switch that can fail is an off-switch
     * nobody trusts, and this is the control the entire consent story rests on (PRD §16).
     */
    public function stop(Trip $trip, StopTripMode $stop): TripResource
    {
        return new TripResource($stop($trip));
    }
}
