<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Actions;

use App\Domain\Context\Contracts\ContextLocationEraser;
use App\Domain\Privacy\Data\LocationErasureReport;
use App\Domain\Recommendations\Contracts\RecommendationTraceEraser;
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
 *  Every raw coordinate the trip's tables hold — the trip anchor, session origins
 *  and destinations, context event locations and their coarse H3 cells, and the
 *  recommendation traces' serve anchors — is nulled in one transaction.
 *
 *  The trace half was a documented gap until E46. This file used to say that
 *  recommendation traces "carry no coordinate columns yet" and defer a
 *  `RecommendationTraceEraser` to E17 — true when it was written, and false the
 *  moment the living feed added `recommendations.anchor` to record where each batch
 *  was ranked from. A new precise-location column on a trip-scoped table is a new
 *  obligation here, not just a new field; the contract exists now and is wired below.
 *
 *  The traces themselves survive: PRD §16 keeps them indefinitely so the replayer
 *  and "why did I get this?" keep working. What is erased is the geography inside
 *  them, which is a different thing from the decision they record.
 */
final class DeleteTripLocationHistory
{
    public function __construct(
        private readonly TripLocationEraser $trips,
        private readonly ContextLocationEraser $context,
        private readonly RecommendationTraceEraser $traces,
    ) {}

    public function __invoke(string $tripId): LocationErasureReport
    {
        return DB::transaction(fn (): LocationErasureReport => new LocationErasureReport(
            tripId: $tripId,
            tripRowsErased: $this->trips->eraseForTrip($tripId),
            contextEventsErased: $this->context->eraseForTrip($tripId),
            tracesErased: $this->traces->eraseForTrip($tripId),
        ));
    }
}
