<?php

declare(strict_types=1);

namespace App\Domain\Trips\Actions;

use App\Domain\Trips\Events\TripModeStarted;
use App\Domain\Trips\Exceptions\TripModeNotAvailable;
use App\Domain\Trips\Models\Trip;
use Carbon\CarbonImmutable;

/**
 * "Follow me for this trip." (PRD §8.2, §14.5 — `POST /trips/{trip}/trip-mode/start`.)
 *
 * The single most consequential switch in the product: past this line the app may use
 * background location, register geofences, and interrupt someone who is not looking at
 * it. PRD §16 calls it out by name — *"No passive companionship unless the user turns it
 * on"* — and PRD risk 4 says getting it wrong kills trust, battery, or app review.
 *
 * So it is explicit, it is timestamped, and it is per-trip. Not per-account: agreeing to
 * be followed around Burgundy in August is not agreeing to be followed around Stockholm
 * in October, and a consent that outlives its context is not a consent.
 */
final class StartTripMode
{
    public function __invoke(Trip $trip, ?CarbonImmutable $at = null): Trip
    {
        if (! $trip->status->isLive()) {
            // A finished trip cannot start following you. The mode is a property of a
            // journey in progress, and there is no journey.
            throw TripModeNotAvailable::tripNotLive($trip->id, $trip->status);
        }

        if ($trip->inTripMode()) {
            return $trip;   // idempotent: pressing the switch twice is pressing it once
        }

        $trip->forceFill([
            'trip_mode_started_at' => $at ?? CarbonImmutable::now(),
            'trip_mode_ended_at' => null,
        ])->save();

        TripModeStarted::dispatch($trip->id, (int) $trip->user_id);

        return $trip;
    }
}
