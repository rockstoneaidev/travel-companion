<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Services;

use App\Domain\Recommendations\Data\ScoringModel;
use InvalidArgumentException;

/**
 * The one resolution seam (SCORING §9.2): scoring functions never read
 * config() — they receive a resolved model, and the trace records its
 * identity. Per-user overrides (Phase 2+) slot in here additively.
 */
final class ScoringModelResolver
{
    public function resolve(): ScoringModel
    {
        $version = (string) config('scoring.active_version');

        return match ($version) {
            'v1' => ScoringModel::v1(),
            default => throw new InvalidArgumentException("Unknown scoring_model_version \"{$version}\"."),
        };
    }
}
