<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Models;

use App\Domain\Context\Enums\ContextSource;
use App\Domain\Places\Casts\AsCoordinates;
use App\Domain\Recommendations\Enums\ServeReason;
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

    /*
     * Real until something with `location_emulate` says otherwise (ADMIN §6).
     *
     * The database default says the same thing, but a freshly created model does not
     * re-read the row, so without this the attribute is NULL in memory and every reader
     * of `->context_source` fataly dereferences it. The default belongs in both places:
     * the column defends the data, this defends the object.
     */
    protected $attributes = ['context_source' => 'device'];

    protected function casts(): array
    {
        return [
            'scores' => 'array',
            'score_inputs' => 'array',
            'coverage_flags' => 'array',
            'cost' => 'array',
            'taxonomy_version' => 'integer',
            'served_at' => 'immutable_datetime',
            // Which batch, why, and where we ranked from (E46). The anchor is
            // per-serve: a session's origin is where it STARTED, not where the
            // feed in front of you was ranked.
            'serve_group' => 'integer',
            'serve_reason' => ServeReason::class,
            // "Propagates onto the decision trace of everything downstream" (ADMIN §6).
            // This is the column the learner and the gold-trace recorder read.
            'context_source' => ContextSource::class,
            'anchor' => AsCoordinates::class,
        ];
    }
}
