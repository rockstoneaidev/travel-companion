<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-user learned taste (PRD §13.3): facet weights, thresholds, and the
 * effective-signal counters. Data, not configuration (SCORING §9.3) —
 * persistence only, the learner owns the update rule.
 */
final class UserTasteProfile extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'facet_weights' => 'array',
            'event_counts' => 'array',
            'calibration_completed_at' => 'immutable_datetime',
        ];
    }

    public static function for(int $userId): self
    {
        // Explicit defaults: firstOrCreate does NOT hydrate DB column defaults
        // on the create path, and a scorer reading tolerance 0 maxes friction
        // on a user's very first feed.
        return self::query()->firstOrCreate(
            ['user_id' => $userId],
            [
                'profile_model_version' => 'v1',
                'facet_weights' => [],
                'event_counts' => [],
                'walk_tolerance_minutes' => 15,
                'price_band' => 2,
            ],
        );
    }

    public function isCalibrated(): bool
    {
        return $this->calibration_completed_at !== null;
    }
}
