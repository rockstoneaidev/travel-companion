<?php

declare(strict_types=1);

namespace App\Domain\Context\Actions;

use App\Domain\Context\Contracts\ContextLocationEraser;
use App\Domain\Context\Models\ContextEvent;

/**
 * Hard-deletes the raw coordinates from a trip's context events (PRD §16 —
 * "Trip-level deletion removes raw *and* derived location data immediately").
 *
 * The derived half is `h3_index`, which is nulled here too: the retention policy
 * coarsens raw pings *to* the H3 cell after 30 days, but an explicit trip-level
 * deletion is a stronger request than the retention schedule and must not leave
 * a coarse trail behind.
 *
 * The events themselves survive as rows — the non-location signals (movement
 * mode, dwell, available minutes) are not location history.
 */
final class EraseContextLocations implements ContextLocationEraser
{
    public function eraseForTrip(string $tripId): int
    {
        return ContextEvent::query()
            ->where('trip_id', $tripId)
            ->update([
                'location' => null,
                'accuracy_meters' => null,
                'h3_index' => null,
            ]);
    }
}
