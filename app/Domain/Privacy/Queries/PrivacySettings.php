<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Queries;

use Illuminate\Support\Facades\DB;

/** What the privacy screen shows (PRD §16). */
final class PrivacySettings
{
    /** @return array<string, mixed> */
    public function forUser(int $userId): array
    {
        $user = DB::table('users')
            ->selectRaw('research_consent, home_zone_radius_meters,
                         ST_Y(home_zone_center::geometry) AS lat, ST_X(home_zone_center::geometry) AS lng')
            ->where('id', $userId)
            ->first();

        return [
            'home_zone' => $user?->lat === null ? null : [
                'lat' => (float) $user->lat,
                'lng' => (float) $user->lng,
                'radius_meters' => (int) $user->home_zone_radius_meters,
            ],
            'research_consent' => (bool) ($user->research_consent ?? false),
            'retention_days' => (int) config('privacy.raw_location_retention_days'),
            'default_radius_meters' => (int) config('privacy.home_zone.default_radius_meters'),
        ];
    }
}
