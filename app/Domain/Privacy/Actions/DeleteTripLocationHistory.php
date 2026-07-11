<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Actions;

use App\Domain\Context\Contracts\ContextLocationEraser;
use App\Domain\Privacy\Data\LocationErasureReport;
use App\Domain\Trips\Contracts\TripLocationEraser;
use Illuminate\Support\Facades\DB;

/**
 * `DELETE /api/v1/trips/{trip}/location-history` (PRD §14.5, §16).
 *
 * Privacy owns the *policy* ("a trip's raw and derived location data is removed
 * immediately, on demand") and orchestrates; each module erases its own tables
 * through a published contract, because a module's tables are its own
 * (conventions/01).
 *
 * ---------------------------------------------------------------------------
 *  E17 SEAM — what this does NOT do yet, deliberately:
 * ---------------------------------------------------------------------------
 *  - **Recommendation traces are not touched.** PRD §16 keeps traces
 *    indefinitely for replay but coarsens *their* location fields to H3 res-8 on
 *    the 30-day schedule. Traces carry no coordinate columns yet (the trace
 *    location lives inside the `score_inputs` JSON that E7 will write), so there
 *    is nothing here to erase and inventing a shape for it now would be
 *    guessing. E17 (privacy & retention) adds a `RecommendationTraceEraser`
 *    contract to this action.
 *  - **The 30-day coarsening job does not exist.** This action is the *on-demand*
 *    path only. The scheduled retention job (raw → H3 res-8 → hard-delete) and
 *    the sensitive-zone / home-zone suppression are E17.
 *
 *  What it DOES do is real and tested: every raw coordinate that E4's own tables
 *  hold for the trip — the trip anchor, session origins and destinations, context
 *  event locations and their coarse H3 cells — is nulled in one transaction.
 */
final class DeleteTripLocationHistory
{
    public function __construct(
        private readonly TripLocationEraser $trips,
        private readonly ContextLocationEraser $context,
    ) {}

    public function __invoke(string $tripId): LocationErasureReport
    {
        return DB::transaction(fn (): LocationErasureReport => new LocationErasureReport(
            tripId: $tripId,
            tripRowsErased: $this->trips->eraseForTrip($tripId),
            contextEventsErased: $this->context->eraseForTrip($tripId),
        ));
    }
}
