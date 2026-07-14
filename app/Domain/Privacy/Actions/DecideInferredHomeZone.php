<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Actions;

use App\Domain\Privacy\Models\InferredHomeZone;
use Illuminate\Support\Facades\DB;

/**
 * The user's answer to "we think you live around here" (E40).
 *
 * Confirm turns the proposal into a real, suppressing home zone — on the CELL CENTROID, so
 * the active zone's centre is a hexagon's middle and not the user's doorstep (the coordinate
 * we never had, we still never have). Reject remembers the "no", so we do not ask again about
 * the same cell.
 */
final class DecideInferredHomeZone
{
    public function __construct(
        private readonly UpdatePrivacySettings $privacy,
    ) {}

    public function confirm(InferredHomeZone $zone): void
    {
        // The cell centroid — computed now, from the cell, and never stored anywhere finer
        // than the cell it came from. This is the one place a coordinate touches the home
        // zone, and it is a hexagon's centre by construction: coarse on purpose.
        $centroid = DB::selectOne(
            'SELECT ST_Y(h3_cell_to_geometry(?::h3index)) AS lat, ST_X(h3_cell_to_geometry(?::h3index)) AS lng',
            [$zone->h3_index, $zone->h3_index],
        );

        $this->privacy->declareHomeZone(
            (int) $zone->user_id,
            (float) $centroid->lat,
            (float) $centroid->lng,
            (int) config('privacy.home_zone.default_radius_meters'),
        );

        $zone->forceFill(['status' => 'confirmed', 'decided_at' => now()])->save();

        // The proposal has done its job and becomes a real home zone. Drop the sibling
        // proposals: the question "where do you live" now has an answer.
        InferredHomeZone::query()
            ->where('user_id', $zone->user_id)
            ->where('id', '!=', $zone->id)
            ->where('status', 'proposed')
            ->delete();
    }

    public function reject(InferredHomeZone $zone): void
    {
        // Remembered, not deleted: a rejected cell must not come back as a fresh proposal the
        // next time the inference runs. "No, that's the hotel" is a durable answer.
        $zone->forceFill(['status' => 'rejected', 'decided_at' => now()])->save();
    }
}
