<?php

declare(strict_types=1);

namespace App\Domain\Context\Contracts;

use App\Domain\Trips\Enums\TravelMode;

/**
 * Real routing — Stage B (PRD §10).
 *
 * A port, not a client, and that is the point: self-hosted OSRM/Valhalla on our
 * own OSM extract is the Phase-2 cost lever (DATA-SOURCES §9), and this interface
 * is what makes that a swap rather than a rewrite.
 *
 * Stage A (the estimator, ±20–30%) does the GATING: it is free, it runs over
 * hundreds of candidates, and its error is acceptable because the reach ceiling
 * already includes dwell and the menu is alternatives, not a schedule.
 *
 * Stage B is for the numbers a user actually SEES — the 3–5 served items. Those
 * had better be real, because someone is going to walk them.
 */
interface Routing
{
    /**
     * Real travel minutes, or null when we could not get them.
     *
     * Null is a first-class answer: the caller keeps the estimator's number and the
     * feed is served. A missing route is never a reason to fail a request.
     */
    public function minutes(float $fromLat, float $fromLng, float $toLat, float $toLng, TravelMode $mode): ?float;
}
