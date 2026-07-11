<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Services;

use App\Domain\Recommendations\Data\ScoringModel;

/**
 * Greedy, diversity-aware feed selection (SCORING §7): a menu of independent
 * alternatives, never an itinerary. Repetition, the cold facet-coverage probe,
 * and duration variety all live here — at selection time, not in the score.
 */
final readonly class FeedSelector
{
    public function __construct(
        private ScoringModel $model,
        private CompositeScorer $scorer,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $candidates  each: sub_scores, friction_raw, type_domain, facets, total_minutes
     * @return list<array<string, mixed>> picked candidates with their final composite + selection trace
     */
    public function select(array $candidates, string $context, float $alpha, int $feedSize): array
    {
        $picked = [];
        $pickedDomains = [];
        $pickedFacets = [];
        $pickedBuckets = [];
        $cold = $alpha < $this->model->feed['cold_alpha_threshold'];

        while (count($picked) < $feedSize && $candidates !== []) {
            $best = null;
            $bestIndex = null;

            foreach ($candidates as $i => $candidate) {
                // §5.2 repetition against what is already picked.
                $repetition = min(1.0, $this->model->feed['repetition_step'] * ($pickedDomains[$candidate['type_domain']] ?? 0));

                // Cold sessions probe unprobed facets (PRD §11 rule c).
                if ($cold && count($candidates) >= 2 && $pickedFacets !== []
                    && $candidate['facets'] !== [] && array_diff($candidate['facets'], $pickedFacets) === []) {
                    continue;
                }

                $result = $this->scorer->composite($candidate['sub_scores'], $context, $alpha, $candidate['friction_raw'], $repetition);
                $score = $result['composite'];

                // Duration variety: a soft tie-breaker among near-equals, never
                // an override of a clearly better item.
                $bucket = $this->bucket((float) ($candidate['total_minutes'] ?? 30));
                if ($best !== null && ! isset($pickedBuckets[$bucket]) && isset($pickedBuckets[$best['bucket']])
                    && abs($score - $best['score']) < 0.02) {
                    $score += 0.001;
                }

                if ($best === null || $score > $best['score']) {
                    $best = ['score' => $score, 'result' => $result, 'repetition' => $repetition, 'bucket' => $bucket];
                    $bestIndex = $i;
                }
            }

            if ($bestIndex === null) {
                break;
            }

            $candidate = $candidates[$bestIndex];
            unset($candidates[$bestIndex]);
            $candidates = array_values($candidates);

            $pickedDomains[$candidate['type_domain']] = ($pickedDomains[$candidate['type_domain']] ?? 0) + 1;
            $pickedFacets = array_values(array_unique([...$pickedFacets, ...$candidate['facets']]));
            $pickedBuckets[$best['bucket']] = true;

            $candidate['composite'] = $best['result']['composite'];
            $candidate['selection'] = [
                'weights' => $best['result']['weights'],
                'alpha' => $best['result']['alpha'],
                'repetition_raw' => $best['repetition'],
                'duration_bucket' => $best['bucket'],
            ];
            $picked[] = $candidate;
        }

        return $picked;
    }

    private function bucket(float $totalMinutes): string
    {
        [$short, $medium] = $this->model->feed['duration_buckets_min'];

        return match (true) {
            $totalMinutes <= $short => 'short',
            $totalMinutes <= $medium => 'medium',
            default => 'long',
        };
    }
}
