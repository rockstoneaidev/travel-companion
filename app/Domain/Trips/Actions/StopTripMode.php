<?php

declare(strict_types=1);

namespace App\Domain\Trips\Actions;

use App\Domain\Trips\Events\TripModeStopped;
use App\Domain\Trips\Models\Trip;
use Carbon\CarbonImmutable;

/**
 * "Stop following me." (PRD §14.5, §16 — a privacy control, not a settings toggle.)
 *
 * Never throws. Turning the companion OFF must work from any state whatsoever — a trip
 * that already ended, a mode that was never on, a client that is retrying because it did
 * not hear us the first time. An off-switch that can fail is an off-switch nobody trusts,
 * and this is the one control the whole consent story rests on.
 */
final class StopTripMode
{
    public function __invoke(Trip $trip, ?CarbonImmutable $at = null): Trip
    {
        if (! $trip->inTripMode()) {
            return $trip;
        }

        $trip->forceFill(['trip_mode_ended_at' => $at ?? CarbonImmutable::now()])->save();

        TripModeStopped::dispatch($trip->id, (int) $trip->user_id);

        return $trip;
    }
}
