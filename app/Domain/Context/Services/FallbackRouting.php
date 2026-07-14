<?php

declare(strict_types=1);

namespace App\Domain\Context\Services;

use App\Domain\Context\Contracts\Routing;
use App\Domain\Trips\Enums\TravelMode;

/**
 * Primary routing, with Google kept in reserve (E43).
 *
 * This is what makes flipping to self-hosted OSRM a low-risk move rather than a leap: if
 * OSRM cannot answer, a served item still gets a REAL number instead of dropping to the
 * ±30% estimator. A route somebody is about to walk should be right, even on the day the
 * new routing box is having a bad time.
 *
 * ## The subtlety: not every null is worth paying to recover
 *
 * OSRM returns null for two reasons — it is DOWN, or there is genuinely NO ROUTE (an
 * island, a foot route across a motorway). We cannot tell them apart from a null, and
 * falling back on every null would quietly pay Google to re-confirm every unroutable pair.
 *
 * That is a deliberately accepted cost, and a tiny one: genuinely unroutable pairs are rare
 * in a feed (the reachability gate has usually already dropped them), and the alternative —
 * plumbing a down/no-route distinction through the port — would complicate every
 * implementation to save fractions of a cent. When OSRM has earned trust on real traffic,
 * `google_fallback` is turned off and this class becomes a passthrough.
 */
final class FallbackRouting implements Routing
{
    public function __construct(
        private readonly OsrmRoutes $primary,
        private readonly GoogleRoutes $fallback,
    ) {}

    public function minutes(float $fromLat, float $fromLng, float $toLat, float $toLng, TravelMode $mode): ?float
    {
        $minutes = $this->primary->minutes($fromLat, $fromLng, $toLat, $toLng, $mode);

        if ($minutes !== null) {
            return $minutes;
        }

        if (! config('routing.osrm.google_fallback')) {
            return null;   // OSRM has earned trust; its null is the final answer
        }

        return $this->fallback->minutes($fromLat, $fromLng, $toLat, $toLng, $mode);
    }
}
