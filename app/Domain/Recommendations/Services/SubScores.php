<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Services;

use App\Domain\Recommendations\Data\ScoringModel;
use App\Domain\Recommendations\Support\Num;

/**
 * The six sub-scores (SCORING §4) as pure functions: every method returns
 * ['value' => float, 'inputs' => raw inputs, 'missing' => gaps] so the trace
 * can recompute it (§2.2) and gaps discount confidence, never zero a score
 * (§2.5).
 */
final readonly class SubScores
{
    public function __construct(
        private ScoringModel $model,
    ) {}

    /**
     * §4.1 — 0.7·max + 0.3·mean over the place's facets.
     *
     * @param  array<string, float>  $facetWeights
     * @param  list<string>  $facets
     */
    public function personalFit(array $facetWeights, array $facets): array
    {
        if ($facets === []) {
            return ['value' => 0.5, 'inputs' => ['facets' => []], 'missing' => ['facets']];
        }

        $weights = array_map(static fn (string $f): float => $facetWeights[$f] ?? 0.5, $facets);

        return [
            'value' => round(0.7 * max($weights) + 0.3 * array_sum($weights) / count($weights), 4),
            'inputs' => ['facets' => $facets, 'weights' => array_combine($facets, $weights)],
            'missing' => [],
        ];
    }

    /**
     * §4.2 — weighted signals, renormalized over what is actually available.
     *
     * @param  array<string, ?float>  $signals  u1–u6, null = not measurable here
     */
    public function uniqueness(array $signals): array
    {
        $available = array_filter($signals, static fn (?float $v): bool => $v !== null);
        $missing = array_keys(array_diff_key($signals, $available));

        if ($available === []) {
            return ['value' => 0.5, 'inputs' => ['signals' => []], 'missing' => $missing];
        }

        $weightSum = 0.0;
        $sum = 0.0;
        foreach ($available as $key => $value) {
            $w = $this->model->uniqueness[$key];
            $weightSum += $w;
            $sum += $w * $value;
        }

        return [
            'value' => round($sum / $weightSum, 4),
            'inputs' => ['signals' => $available],
            'missing' => $missing,
        ];
    }

    /** §4.3 — slack until last feasible start, half-life 8 h, special-moment floor. */
    public function temporalUrgency(float $slackMinutes, bool $specialMomentOpen = false): array
    {
        $value = Num::decay(max(0.0, $slackMinutes), $this->model->urgency['half_life_minutes']);

        if ($specialMomentOpen) {
            $value = max($value, $this->model->urgency['special_moment_floor']);
        }

        return [
            'value' => round($value, 4),
            'inputs' => ['slack_min' => round($slackMinutes, 2), 'special_moment' => $specialMomentOpen],
            'missing' => [],
        ];
    }

    /** §4.4 — destination context only; caller drops the term otherwise. */
    public function routeFit(float $detourMinutes, float $budgetSlackMinutes, float $walkToleranceMinutes): array
    {
        $allowance = min($this->model->routeFit['allowance_fraction'] * $budgetSlackMinutes, $walkToleranceMinutes);

        return [
            'value' => round(1.0 - Num::ramp($detourMinutes, $this->model->routeFit['on_route_minutes'], max($allowance, $this->model->routeFit['on_route_minutes'] + 0.01)), 4),
            'inputs' => ['detour_min' => round($detourMinutes, 2), 'allowance_min' => round($allowance, 2)],
            'missing' => [],
        ];
    }

    /**
     * §4.5 — 0.6^n_type × 0.9^n_domain with 4-day half-life recency.
     *
     * @param  list<array{event: string, age_days: float, same_type: bool}>  $tripEvents  same-domain events, split by type match
     */
    public function novelty(array $tripEvents): array
    {
        $nType = 0.0;
        $nDomain = 0.0;

        foreach ($tripEvents as $event) {
            $weight = ($this->model->novelty['event_weights'][$event['event']] ?? 0.0)
                * Num::decay($event['age_days'], $this->model->novelty['half_life_days']);

            $event['same_type'] ? $nType += $weight : $nDomain += $weight;
        }

        return [
            'value' => round($this->model->novelty['type_factor'] ** $nType * $this->model->novelty['domain_factor'] ** $nDomain, 4),
            'inputs' => ['n_type' => round($nType, 3), 'n_domain' => round($nDomain, 3)],
            'missing' => [],
        ];
    }

    /**
     * §4.6 — credibility × agreement × freshness × coverage. Never LLM certainty.
     *
     * @param  list<string>  $tiers  credibility tiers present (official|reference|open|community)
     * @param  list<string>  $missingSignalGroups  accumulated §2.5 gaps
     */
    public function confidence(array $tiers, int $conflictGroups, array $missingSignalGroups, float $ageOverTtl): array
    {
        $c = $this->model->confidence;

        if ($tiers === []) {
            return ['value' => 0.0, 'inputs' => ['tiers' => []], 'missing' => ['evidence']];
        }

        $cred = max(array_map(static fn (string $t): float => $c['tier_values'][$t] ?? 0.4, $tiers));
        $corrob = min((count($tiers) - 1) * $c['corroboration_step'], $c['corroboration_cap']);
        $conflict = $conflictGroups * $c['conflict_step'];
        $coverage = max(count($missingSignalGroups) * $c['coverage_step'], $c['coverage_cap']);
        $fresh = 1.0 - 0.5 * Num::ramp($ageOverTtl, $c['freshness_ramp'][0], $c['freshness_ramp'][1]);

        $value = Num::clamp($cred + $corrob + $conflict + $coverage) * $fresh;

        // Tier-D-only evidence never establishes existence (DATA-SOURCES §1.2).
        if (! array_diff($tiers, ['community'])) {
            $value = min($value, $c['tier_d_cap']);
        }

        return [
            'value' => round($value, 4),
            'inputs' => [
                'tiers' => $tiers, 'cred' => $cred, 'corrob' => $corrob,
                'conflict_groups' => $conflictGroups, 'coverage_gaps' => $missingSignalGroups,
                'age_over_ttl' => round($ageOverTtl, 3),
            ],
            'missing' => [],
        ];
    }

    /** §5.1 — saturating-additive friction against this user's thresholds. */
    public function frictionRaw(float $walkMinutes, float $walkToleranceMinutes, ?int $priceBandsAbove, string $queueRisk, float $weather, float $effort): array
    {
        $f = $this->model->friction;

        $timeC = Num::ramp($walkMinutes, 0.0, $f['time_ramp_tolerance_factor'] * $walkToleranceMinutes);
        $priceC = $priceBandsAbove === null ? $f['price_unknown'] : match (true) {
            $priceBandsAbove <= 0 => 0.0,
            $priceBandsAbove === 1 => 0.5,
            default => 1.0,
        };
        $queueC = $f['queue'][$queueRisk] ?? $f['queue']['low'];

        $raw = Num::clamp(
            $f['coefficients']['time'] * $timeC
            + $f['coefficients']['price'] * $priceC
            + $f['coefficients']['queue'] * $queueC
            + $f['coefficients']['weather'] * $weather
            + $f['coefficients']['effort'] * $effort,
        );

        return [
            'value' => round($raw, 4),
            'inputs' => [
                'walk_min' => $walkMinutes, 'tolerance_min' => $walkToleranceMinutes,
                'time_c' => round($timeC, 4), 'price_c' => $priceC, 'queue_c' => $queueC,
                'weather_c' => $weather, 'effort_c' => $effort,
            ],
            'missing' => [],
        ];
    }
}
