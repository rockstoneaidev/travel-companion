<?php

declare(strict_types=1);

namespace App\Domain\Trips\Actions;

use App\Domain\Places\Data\Coordinates;
use App\Domain\Trips\Enums\TripSource;
use App\Domain\Trips\Enums\TripStatus;
use App\Domain\Trips\Events\TripStarted;
use App\Domain\Trips\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The implicit trip clustering (PRD §6.6). The user never creates a trip; a
 * session either joins the live one or opens a new one.
 *
 * The rule, and it is the whole rule:
 *
 *   join the user's live trip  ⟺  it is within `region_radius_meters` of the new
 *                                 session's origin AND its last session was less
 *                                 than `max_gap_days` ago.
 *
 * Both thresholds are config (config/trips.php) and the version they were
 * applied under is stamped on the trip (`clustering_version`, PRD §15.1) —
 * attribution is derived, so it is recomputable, so the thresholds are
 * low-stakes.
 *
 * "At most one active trip per user" is also a partial unique index
 * (`trips_one_active_per_user`); the row lock below is what stops two devices
 * from finding out about it the hard way.
 */
final class ResolveOrCreateTripForSession
{
    public function __invoke(int $userId, Coordinates $origin, CarbonImmutable $at): Trip
    {
        return DB::transaction(function () use ($userId, $origin, $at): Trip {
            $liveTrip = Trip::query()
                ->where('user_id', $userId)
                ->where('status', TripStatus::Active)
                ->lockForUpdate()
                ->first();

            if ($liveTrip !== null && $this->joins($liveTrip, $origin, $at)) {
                $liveTrip->forceFill(['last_session_at' => $at])->save();

                return $liveTrip;
            }

            // Too far, or too long ago: the old trip is over and a new one begins.
            if ($liveTrip !== null) {
                $liveTrip->forceFill([
                    'status' => TripStatus::Completed,
                    'ended_at' => $liveTrip->last_session_at ?? $at,
                ])->save();
            }

            $trip = Trip::query()->create([
                'user_id' => $userId,
                'name' => null,
                'status' => TripStatus::Active,
                'source' => TripSource::Auto,
                'anchor_point' => $origin,
                'clustering_version' => config('trips.clustering.version'),
                'started_at' => $at,
                'last_session_at' => $at,
            ]);

            TripStarted::dispatch($trip->id, $userId);

            return $trip;
        });
    }

    private function joins(Trip $trip, Coordinates $origin, CarbonImmutable $at): bool
    {
        $anchor = $trip->anchor_point;
        $lastSessionAt = $trip->last_session_at ?? $trip->started_at;

        // A trip whose location history was erased (PRD §16) has no region to
        // compare against; it can no longer absorb sessions.
        if ($anchor === null || $lastSessionAt === null) {
            return false;
        }

        $withinGap = $lastSessionAt->diffInDays($at, absolute: true) < (float) config('trips.clustering.max_gap_days');

        if (! $withinGap) {
            return false;
        }

        return $this->metersBetween($anchor, $origin) <= (float) config('trips.clustering.region_radius_meters');
    }

    /** Great-circle distance, computed by PostGIS so it agrees with every other distance in the app. */
    private function metersBetween(Coordinates $a, Coordinates $b): float
    {
        $meters = DB::selectOne(
            'select ST_Distance(ST_GeogFromText(?), ST_GeogFromText(?)) as meters',
            ["SRID=4326;{$a->toWkt()}", "SRID=4326;{$b->toWkt()}"],
        );

        return (float) ($meters->meters ?? INF);
    }
}
