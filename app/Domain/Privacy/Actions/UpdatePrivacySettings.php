<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Actions;

use Illuminate\Support\Facades\DB;

/**
 * The user acting on their own privacy settings (PRD §16).
 *
 * In the domain rather than a controller because these are POLICY writes, not form
 * handling — and because a controller that reaches for the database is a controller
 * that will eventually make a policy decision in a route file (conventions/04, and
 * the arch test that caught me doing exactly this).
 */
final class UpdatePrivacySettings
{
    public function declareHomeZone(int $userId, float $lat, float $lng, int $radiusMeters): void
    {
        DB::statement(
            'UPDATE users SET home_zone_center = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                              home_zone_radius_meters = ? WHERE id = ?',
            [$lng, $lat, $radiusMeters, $userId],
        );
    }

    /** A privacy control you cannot undo is a trap, not a control. */
    public function forgetHomeZone(int $userId): void
    {
        DB::table('users')->where('id', $userId)->update([
            'home_zone_center' => null,
            'home_zone_radius_meters' => null,
        ]);
    }

    public function setResearchConsent(int $userId, bool $consent): void
    {
        DB::table('users')->where('id', $userId)->update(['research_consent' => $consent]);
    }
}
