<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use Illuminate\Support\Facades\DB;

/**
 * "Do we know this place at all?" (PRD §8.1 graceful degradation, §15.3 coverage honesty.)
 *
 * The world model is region-scoped by design — Stockholm municipality and seven French
 * cities (`IngestRegion`). Everywhere else, `places_core` is simply empty, and the feed
 * has nothing to say because there is nothing there.
 *
 * That is fine. What is NOT fine is the empty screen we showed for it. Both cases —
 * "we swept this neighbourhood and nothing is worth your time right now" and "we have
 * never heard of this town" — rendered the same warm line:
 *
 *     "You're in a good spot — I'm watching the places around you."
 *
 * We were not watching. The founder dropped a pin in Skellefteå (35,000 people, 700 km
 * north of the launch region) and the app claimed to be keeping an eye on it. PRD §8.1
 * asks for the opposite: "graceful degradation elsewhere — *we don't know this area
 * deeply yet*", and §15.3 says a bounded coverage must never be silently false.
 *
 * One indexed count against our own table answers it. No scouts, no APIs, no cost.
 *
 * A SERVICE, not a Query, because Sources asks it too (E48: "do we already know here, or
 * should I go and learn it?") and a module's `Queries` are its own (conventions/01 —
 * the arch test caught this the moment the region-learner reached for it).
 */
final class PlaceDensity
{
    public function within(float $lat, float $lng, int $meters): int
    {
        return (int) DB::scalar(
            'SELECT count(*) FROM places_core
              WHERE ST_DWithin(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
            [$lng, $lat, $meters],
        );
    }
}
