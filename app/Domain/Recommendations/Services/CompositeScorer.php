<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Services;

use App\Domain\Recommendations\Data\ScoringModel;
use App\Domain\Recommendations\Support\Num;

/**
 * The composite (SCORING §6): W(context, α) · S(c) − penalties. Context gates
 * route_fit (pure-radius drops it, weights renormalize by construction of the
 * radius vectors); α interpolates cold → warm within the context.
 */
final readonly class CompositeScorer
{
    public function __construct(
        private ScoringModel $model,
    ) {}

    /**
     * @param  array<string, float>  $subScores  term => value (route_fit absent in radius context)
     * @return array{composite: float, weighted: float, weights: array<string, float>, alpha: float}
     */
    public function composite(array $subScores, string $context, float $alpha, float $frictionRaw, float $repetitionRaw): array
    {
        $weights = $this->effectiveWeights($context, $alpha);

        $weighted = 0.0;
        foreach ($weights as $term => $weight) {
            $weighted += $weight * ($subScores[$term] ?? 0.0);
        }

        $composite = $weighted
            - $this->model->penaltyWeights['friction'] * $frictionRaw
            - $this->model->penaltyWeights['repetition'] * $repetitionRaw;
        // interruption_penalty: Phase 2 stub, raw ≡ 0 (SCORING §5.3)

        return [
            'composite' => round($composite, 4),
            'weighted' => round($weighted, 4),
            'weights' => $weights,
            'alpha' => round($alpha, 4),
        ];
    }

    /** @return array<string, float> α-interpolated vector for the context. */
    public function effectiveWeights(string $context, float $alpha): array
    {
        $warm = $this->model->weights['warm'][$context];
        $cold = $this->model->weights['cold'][$context];

        $out = [];
        foreach ($warm as $term => $w) {
            $out[$term] = round($alpha * $w + (1 - $alpha) * $cold[$term], 4);
        }

        return $out;
    }

    /**
     * §6 cold start: α from effective feedback signals, floored at 0.4 once
     * calibration is complete.
     *
     * @param  array<string, int>  $eventCounts  event => count
     */
    public function alpha(array $eventCounts, bool $calibrated): float
    {
        $nEff = 0.0;
        foreach ($eventCounts as $event => $count) {
            $nEff += ($this->model->alpha['n_eff_weights'][$event] ?? 0.0) * $count;
        }

        $alpha = Num::clamp($nEff / $this->model->alpha['full_warm_n_eff']);

        return $calibrated ? max($alpha, $this->model->alpha['floor_after_calibration']) : $alpha;
    }
}
