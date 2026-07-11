<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * What was actually served, with the full decision trace — raw sub-score
 * inputs included so the replayer can refit constants (SCORING §2.2,
 * PRD §15). PROPRIETARY-SHELL ZONE.
 *
 * Holds opportunity_id / user_id as plain keys: cross-module traffic goes
 * through contracts and DTOs, never another module's Eloquent models
 * (conventions/01 — enforced by tests/Arch/ConventionsTest.php).
 */
final class Recommendation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scores' => 'array',
            'score_inputs' => 'array',
            'coverage_flags' => 'array',
            'cost' => 'array',
            'taxonomy_version' => 'integer',
            'served_at' => 'immutable_datetime',
        ];
    }
}
