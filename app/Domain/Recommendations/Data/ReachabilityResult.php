<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Data;

/**
 * One gate decision with its full breakdown — the raw inputs the trace
 * stores (SCORING §2.2), so the replayer can recompute it.
 */
final readonly class ReachabilityResult
{
    public function __construct(
        public bool $reachable,
        public float $travelMinutes,
        public int $dwellMinutes,
        public float $returnMinutes,
        public float $totalMinutes,
        public float $remainingMinutes,
    ) {}

    /** @return array<string, mixed> trace shape */
    public function toTrace(): array
    {
        return [
            'reachable' => $this->reachable,
            'travel_min' => $this->travelMinutes,
            'dwell_min' => $this->dwellMinutes,
            'return_min' => $this->returnMinutes,
            'total_min' => $this->totalMinutes,
            'remaining_min' => $this->remainingMinutes,
        ];
    }
}
