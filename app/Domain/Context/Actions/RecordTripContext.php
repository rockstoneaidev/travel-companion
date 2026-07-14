<?php

declare(strict_types=1);

namespace App\Domain\Context\Actions;

use App\Domain\Context\Data\NewTripContextData;
use App\Domain\Context\Data\TripContextResult;
use App\Domain\Context\Models\ContextEvent;
use App\Domain\Places\Contracts\TileIndexer;
use App\Domain\Privacy\Services\HomeZone;
use App\Domain\Trips\Contracts\TripLookup;
use App\Domain\Trips\Data\TripData;
use App\Jobs\Ranking\DetectVisitsJob;
use App\Jobs\Ranking\InferTripSegmentsJob;

/**
 * The background stream — a phone in a pocket, noticing things (E29; PRD §13.4).
 *
 * Phase 1's context event is a child of an explore session: "I have three hours from
 * here." This one has no session. The app is closed, the user is walking, and the phone
 * decided something changed enough to be worth a network call.
 *
 * Three rules are ENFORCED here rather than trusted to the client, because a rule that
 * lives only in the mobile app is a rule that lasts until the next release.
 *
 * ## 1. Trip Mode must be ON
 *
 * "No passive companionship unless the user turns it on" (PRD §16). A background event
 * for a trip whose mode is off is not stored, not coarsened, not counted — it is refused.
 * The client should not have sent it; the server does not care whether it meant to.
 *
 * ## 2. NEVER A RAW GPS STREAM
 *
 * PRD §13.4, in italics: the phone sends *meaningful context changes* — "Never 'GPS every
 * 5 seconds → backend reasons → push'". That is a design promise about battery and about
 * trust, and it is exactly the kind of promise that erodes: a client bug, a retry loop,
 * an over-eager summariser, and suddenly we are holding a second-by-second track of
 * somebody's day.
 *
 * So the floor is enforced server-side. An event that is neither far enough nor long
 * enough from the last one we kept is DISCARDED — not throttled, not queued, discarded.
 * The caller gets a 202 and the honest truth that we did not keep it.
 *
 * ## 3. NO TRACKING AT HOME
 *
 * PRD §13.4's power tiers end with "No tracking: at home". The session path suppresses the
 * *coordinate* inside the home zone and keeps the coarse cell — reasonable, because the
 * user is actively looking at a screen and asked for something.
 *
 * Background is different, and stricter: nobody asked. So an event inside the home zone
 * is dropped ENTIRELY — no coordinate, no cell, no row. A trail of coarse cells at a
 * person's home address, gathered while they were not using the app, is precisely the
 * thing this product promises never to hold.
 */
final class RecordTripContext
{
    public function __construct(
        private readonly TileIndexer $tiles,
        // Through Trips' published contract, never its models (conventions/01 — the arch
        // test caught me holding a Trip the moment I wrote this).
        private readonly TripLookup $trips,
    ) {}

    public function __invoke(string $tripId, NewTripContextData $data): TripContextResult
    {
        $trip = $this->trips->find($tripId);

        if ($trip === null || ! $trip->inTripMode) {
            return TripContextResult::refused('trip_mode_off');
        }

        $home = HomeZone::forUser($trip->userId);

        if ($home->declared() && $home->contains($data->location->lat, $data->location->lng)) {
            // Not "recorded without a coordinate". Not recorded.
            return TripContextResult::refused('home_zone');
        }

        if (! $this->meaningful($trip, $data)) {
            return TripContextResult::refused('not_meaningful');
        }

        $event = ContextEvent::query()->create([
            // No explore session. That is the point — this is a trip's event, not a
            // session's, and inventing a session to hang it off would be a lie in a table.
            'explore_session_id' => null,
            'trip_id' => $trip->id,
            'user_id' => $trip->userId,

            // Provenance from the TRIP, never from the request (ADMIN §6, E47). An
            // emulated trip's background stream must not teach a taste profile.
            'context_source' => $trip->contextSource,

            'occurred_at' => $data->occurredAt(),
            'location' => $data->location,
            'h3_index' => $this->tiles->cellFor($data->location->lat, $data->location->lng),
            'accuracy_meters' => $data->accuracyMeters,
            'movement_mode' => $data->movementMode,
            'speed_mps' => $data->speedMps,
            'heading' => $data->heading,
            'app_state' => $data->appState,
            'power_tier' => $data->powerTier,
            'battery_level' => $data->batteryLevel,
            'is_low_power_mode' => $data->isLowPowerMode,
        ]);

        /*
         * The trip just learned something about its own shape (E38). Re-read it.
         *
         * Deliberately fire-and-forget on the queue: a background ping is the cheapest
         * thing in the system and must stay that way — the handset is on battery, and the
         * one hard guardrail Trip Mode has to clear is that watching somebody costs them
         * nothing they can feel. The job is unique-per-trip for five minutes, so a walk
         * across a city re-classifies the day a handful of times, not a hundred.
         */
        InferTripSegmentsJob::dispatch($tripId);

        /*
         * ...and look for a visit in it (E37). This is what background location is FOR:
         * the north star counts places the traveller actually went to, and until now the
         * only way to know was to ask them — which measures the kind of person who answers
         * prompts, not the kind of place worth going to.
         */
        DetectVisitsJob::dispatch($tripId);

        return TripContextResult::recorded($event);
    }

    /**
     * Is this actually a CHANGE, or is it a stream pretending to be one?
     *
     * The floor is deliberately generous — it is not trying to second-guess a good
     * summariser, only to make a bad one harmless. A phone that behaves as PRD §13.4
     * describes will never notice this method exists.
     */
    private function meaningful(TripData $trip, NewTripContextData $data): bool
    {
        /** @var ContextEvent|null $last */
        $last = ContextEvent::query()
            ->where('trip_id', $trip->id)
            ->whereNotNull('location')
            ->orderByDesc('occurred_at')
            ->first();

        if ($last === null || $last->location === null) {
            return true;   // the first fix of a trip is always meaningful
        }

        $seconds = $last->occurred_at->diffInSeconds($data->occurredAt(), absolute: true);
        $metres = $last->location->distanceTo($data->location);

        /*
         * Far enough OR long enough. Either alone is a meaningful change:
         *
         *  - You moved 300 m. That is a different place, whatever the clock says.
         *  - Ten minutes passed. That is a different moment, even standing still — the
         *    light changed, the market opened, the museum closed.
         *
         * Requiring BOTH would discard a traveller who sat still in a café for an hour and
         * then walked out, which is the exact moment the companion exists for.
         */
        return $metres >= (float) config('trips.trip_mode.min_distance_meters')
            || $seconds >= (int) config('trips.trip_mode.min_interval_seconds');
    }
}
