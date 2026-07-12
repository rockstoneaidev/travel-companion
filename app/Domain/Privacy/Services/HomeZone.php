<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Services;

use Illuminate\Support\Facades\DB;

/**
 * The declared home zone (PRD §16, E17) — Phase 1's entire sensitive-zone scope.
 *
 * Three rules, and they are separate on purpose because they fail separately:
 *
 *   NO SERVING   — no opportunity inside the zone reaches the feed. Being told
 *                  about the café at the end of your own street is not a travel
 *                  recommendation, it is surveillance with a suggestion attached.
 *   NO LEARNING  — nothing inside the zone teaches the taste profile. Your home is
 *                  where you are for reasons that have nothing to do with taste,
 *                  and letting it vote would poison the model with your commute.
 *   NO PRECISION — context events inside the zone keep only their H3 cell. The
 *                  coordinates are never written, not even for 30 days.
 *
 * The third is the one that matters most, because it is the only one that cannot
 * be fixed retroactively: you cannot un-store a coordinate.
 *
 * Loaded once per session and asked many times — the check is on the serve path
 * for every candidate.
 */
final class HomeZone
{
    public function __construct(
        private readonly ?float $lat = null,
        private readonly ?float $lng = null,
        private readonly ?int $radiusMeters = null,
    ) {}

    public static function forUser(int $userId): self
    {
        $row = DB::table('users')
            ->selectRaw('ST_Y(home_zone_center::geometry) AS lat, ST_X(home_zone_center::geometry) AS lng, home_zone_radius_meters AS radius')
            ->where('id', $userId)
            ->first();

        if ($row === null || $row->lat === null) {
            return new self;   // not declared — nothing is suppressed
        }

        return new self(
            (float) $row->lat,
            (float) $row->lng,
            (int) ($row->radius ?? config('privacy.home_zone.default_radius_meters')),
        );
    }

    public function declared(): bool
    {
        return $this->lat !== null;
    }

    /** Is this point inside the zone? Undeclared zones suppress nothing. */
    public function contains(float $lat, float $lng): bool
    {
        if (! $this->declared()) {
            return false;
        }

        return $this->metresFromCentre($lat, $lng) <= (float) $this->radiusMeters;
    }

    /**
     * Haversine, in PHP, on purpose: this runs once per candidate on the serve path
     * and a round-trip to PostGIS per candidate would be absurd for a distance
     * between two points we already hold in memory.
     */
    private function metresFromCentre(float $lat, float $lng): float
    {
        $earthRadius = 6_371_000.0;

        $dLat = deg2rad($lat - (float) $this->lat);
        $dLng = deg2rad($lng - (float) $this->lng);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad((float) $this->lat)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;

        return 2 * $earthRadius * asin(min(1.0, sqrt($a)));
    }
}
