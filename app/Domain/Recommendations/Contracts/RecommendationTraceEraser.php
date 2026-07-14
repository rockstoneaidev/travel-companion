<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Contracts;

/**
 * The Recommendations half of trip-level location deletion (PRD §16, §14.5).
 *
 * `DeleteTripLocationHistory` named this contract as a seam and could not implement
 * it, for an honest reason: traces held no coordinate COLUMN — the geography lived
 * inside the `score_inputs` JSON — and inventing a shape for it early would have
 * been guessing.
 *
 * E46 stopped it being a guess. The living feed has to record where each batch was
 * ranked from, so `recommendations.anchor` exists, and it is precise location on a
 * trace belonging to a trip. The moment that column landed, "delete my location
 * history for this trip" acquired something here it was silently missing.
 *
 * The trace itself SURVIVES — that is the point of the split. Recommendations are
 * kept indefinitely so "why did I get this?" stays answerable and the replayer keeps
 * working (§15.2). What goes is the geography: the coordinate is nulled, the res-8
 * cell stays, and the decision remains readable without remaining locatable.
 */
interface RecommendationTraceEraser
{
    /** @return int number of traces whose precise anchor was erased */
    public function eraseForTrip(string $tripId): int;
}
