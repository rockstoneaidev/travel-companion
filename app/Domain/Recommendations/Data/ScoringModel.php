<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Data;

/**
 * One immutable constant set per scoring_model_version (SCORING §9.1). Every
 * number from SCORING.md v1 lives here; config only selects which version is
 * active, and no constant is ever env-tunable. Changing anything mints v2.
 */
final readonly class ScoringModel
{
    private function __construct(
        public string $version,
        public array $weights,          // [warm|cold][route|radius] => [term => w]
        public array $penaltyWeights,   // friction, repetition, interruption
        public array $friction,         // coefficients + defaults
        public array $urgency,          // half_life_minutes, special_moment_floor
        public array $novelty,          // type_factor, domain_factor, half_life_days, event_weights
        public array $uniqueness,       // signal weights u1–u6
        public array $confidence,       // tier values, corrob, conflict, coverage, tier_d_cap
        public array $routeFit,         // on_route_minutes, allowance_fraction
        public array $alpha,            // n_eff weights, divisor, floor_after_calibration
        public array $feed,             // cold_alpha_threshold, repetition_step, duration buckets
    ) {}

    public static function v1(): self
    {
        return new self(
            version: 'v1',
            weights: [
                'warm' => [
                    'route' => ['personal_fit' => .30, 'uniqueness' => .20, 'temporal_urgency' => .15, 'route_fit' => .15, 'novelty' => .10, 'confidence' => .10],
                    'radius' => ['personal_fit' => .35, 'uniqueness' => .23, 'temporal_urgency' => .18, 'novelty' => .12, 'confidence' => .12],
                ],
                'cold' => [
                    'route' => ['personal_fit' => .05, 'uniqueness' => .30, 'temporal_urgency' => .25, 'route_fit' => .15, 'novelty' => .05, 'confidence' => .20],
                    'radius' => ['personal_fit' => .06, 'uniqueness' => .35, 'temporal_urgency' => .29, 'novelty' => .06, 'confidence' => .24],
                ],
            ],
            penaltyWeights: ['friction' => .25, 'repetition' => .15, 'interruption' => .20],
            friction: [
                'coefficients' => ['time' => .45, 'price' => .25, 'queue' => .15, 'weather' => .20, 'effort' => .15],
                'time_ramp_tolerance_factor' => 1.5,
                'price_unknown' => 0.3,
                'queue' => ['low' => .1, 'medium' => .4, 'high' => .8],
                'default_walk_tolerance_min' => 15,
            ],
            urgency: ['half_life_minutes' => 480.0, 'special_moment_floor' => 0.7],
            novelty: ['type_factor' => 0.6, 'domain_factor' => 0.9, 'half_life_days' => 4.0,
                'event_weights' => ['visited' => 1.0, 'accepted' => 0.7, 'saved' => 0.3, 'ignored' => 0.0]],
            uniqueness: ['u1' => .25, 'u2' => .20, 'u3' => .20, 'u4' => .15, 'u5' => .10, 'u6' => .10,
                'temporal_rarity' => ['event' => 1.0, 'seasonal' => 0.7, 'ephemeral' => 0.4, 'evergreen' => 0.0]],
            confidence: [
                'tier_values' => ['official' => .95, 'reference' => .85, 'open' => .70, 'community' => .40],
                'corroboration_step' => 0.05, 'corroboration_cap' => 0.15,
                'conflict_step' => -0.15, 'coverage_step' => -0.05, 'coverage_cap' => -0.15,
                'tier_d_cap' => 0.40,
                'freshness_ramp' => [0.5, 1.0],
            ],
            routeFit: ['on_route_minutes' => 3.0, 'allowance_fraction' => 0.35],
            alpha: ['n_eff_weights' => ['visited' => 5.0, 'dismissed' => 4.0, 'saved' => 3.0, 'accepted' => 2.0, 'ignored' => 0.25],
                'full_warm_n_eff' => 20.0, 'floor_after_calibration' => 0.4],
            feed: ['cold_alpha_threshold' => 0.7, 'repetition_step' => 0.5,
                'duration_buckets_min' => [45, 120]],
        );
    }
}
