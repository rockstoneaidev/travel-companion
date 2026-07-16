<?php

declare(strict_types=1);

namespace App\Domain\Trips\Actions;

use App\Domain\Trips\Data\NewExploreSessionData;
use App\Domain\Trips\Enums\TravelMode;
use App\Domain\Trips\Enums\TripStatus;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "Start exploring" a planned trip — the thing that turns a plan into a live trip.
 *
 * A planned trip used to be a dead end: you could name it and pin a location, and then it
 * sat at "planned" forever with no way to begin. This is the door. It activates the trip and
 * opens an explore session at the location the planner chose, so the feed comes alive there.
 *
 * ## Why it can force the trip
 *
 * Normally a session's trip is decided by implicit clustering (resolve-or-create), which only
 * ever looks at the ACTIVE trip and ignores planned ones — that is deliberate (CreateTrip's
 * docblock). So starting a planned trip has to be explicit: we activate it here, complete any
 * other active trip (at most one active per user), and hand the session a `forceTripId` so it
 * attaches to THIS trip rather than clustering into a fresh one.
 */
final class StartPlannedTrip
{
    public function __construct(
        private readonly StartExploreSession $start,
    ) {}

    public function __invoke(Trip $trip, int $timeBudgetMinutes, TravelMode $travelMode): ExploreSession
    {
        $anchor = $trip->anchor_point;

        if ($anchor === null) {
            // Nowhere to explore. The planner set no location, so there is no session to open.
            throw new RuntimeException('A trip needs a location before you can start exploring it.');
        }

        $now = CarbonImmutable::now();

        DB::transaction(function () use ($trip, $now): void {
            // One active trip per user: any other live trip ends as this one begins.
            Trip::query()
                ->where('user_id', $trip->user_id)
                ->where('status', TripStatus::Active)
                ->where('id', '!=', $trip->id)
                ->update(['status' => TripStatus::Completed->value, 'ended_at' => $now, 'updated_at' => $now]);

            $trip->forceFill([
                'status' => TripStatus::Active,
                'started_at' => $trip->started_at ?? $now,
                'last_session_at' => $now,
            ])->save();
        });

        return ($this->start)(new NewExploreSessionData(
            userId: (int) $trip->user_id,
            origin: $anchor,
            timeBudgetMinutes: $timeBudgetMinutes,
            travelMode: $travelMode,
            forceTripId: $trip->id,
        ));
    }
}
