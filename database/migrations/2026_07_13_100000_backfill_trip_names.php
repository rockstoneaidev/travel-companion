<?php

declare(strict_types=1);

use App\Domain\Places\Data\Coordinates;
use App\Domain\Trips\Services\NameTrip;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Name the trips that were born before we knew how to name them.
 *
 * NameTrip derives "Stockholm, July" from the trip's anchor, and it runs when a trip
 * is CREATED. Every trip that already existed therefore kept its null name forever and
 * goes on rendering as "Untitled trip" — which is the least useful string it is
 * possible to put on a list of somebody's memories.
 *
 * The anchor is the honest source: where the trip actually formed. Where a trip has
 * none, its first session's origin is the same fact by another route. And where we can
 * say nothing truthfully — a trip outside every region we hold — it stays null, because
 * a wrong name is worse than no name.
 */
return new class extends Migration
{
    public function up(): void
    {
        $name = app(NameTrip::class);

        $trips = DB::table('trips')
            ->whereNull('name')
            ->selectRaw('id, started_at, created_at,
                ST_Y(anchor_point::geometry) AS lat,
                ST_X(anchor_point::geometry) AS lng')
            ->get();

        foreach ($trips as $trip) {
            $lat = $trip->lat;
            $lng = $trip->lng;

            if ($lat === null || $lng === null) {
                // No anchor: the first session's origin is where this trip formed.
                $origin = DB::table('explore_sessions')
                    ->where('trip_id', $trip->id)
                    ->orderBy('started_at')
                    ->selectRaw('ST_Y(origin::geometry) AS lat, ST_X(origin::geometry) AS lng')
                    ->first();

                $lat = $origin->lat ?? null;
                $lng = $origin->lng ?? null;
            }

            if ($lat === null || $lng === null) {
                continue;
            }

            $derived = $name(
                new Coordinates(lat: (float) $lat, lng: (float) $lng),
                CarbonImmutable::parse($trip->started_at ?? $trip->created_at),
            );

            if ($derived === null) {
                continue;   // outside every region we hold: no name beats a wrong one
            }

            DB::table('trips')->where('id', $trip->id)->update(['name' => $derived]);
        }
    }

    public function down(): void
    {
        // Not reversible: we cannot tell a backfilled name from one a user typed, and
        // wiping the latter to undo the former would destroy the thing we were fixing.
    }
};
