<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Services;

use App\Domain\Places\Enums\PlaceType;
use App\Domain\Recommendations\Data\ReachabilityResult;
use App\Domain\Trips\Enums\TravelMode;
use DateTimeImmutable;

/**
 * PRD §10 step 8 — a HARD FILTER, never a down-rank (SCORING §2.1): a
 * candidate is served only if travel + dwell + the way home (or onward to the
 * destination) fits the remaining budget. Excluded candidates carry their
 * breakdown so traces can show *why* (conventions/08).
 */
final class ReachabilityGate
{
    public function __construct(
        private readonly TravelTimeEstimator $estimator,
    ) {}

    /**
     * @param  array{lat: float, lng: float, type?: ?string, dwell_minutes?: ?int}  $candidate
     */
    public function evaluate(
        array $candidate,
        float $originLat,
        float $originLng,
        TravelMode $mode,
        float $remainingMinutes,
        ?float $destLat = null,
        ?float $destLng = null,
    ): ReachabilityResult {
        $travel = $this->estimator->minutes($originLat, $originLng, $candidate['lat'], $candidate['lng'], $mode);

        // Opportunity-level dwell override beats the type default (PRD §10).
        $dwell = $candidate['dwell_minutes']
            ?? (isset($candidate['type']) && $candidate['type'] !== null
                ? PlaceType::from($candidate['type'])->typicalDwellMinutes()
                : 20);

        // Return leg: pure-radius sessions come back to the origin; destination
        // sessions continue onward from the candidate instead.
        $return = $destLat !== null && $destLng !== null
            ? $this->estimator->minutes($candidate['lat'], $candidate['lng'], $destLat, $destLng, $mode)
            : $this->estimator->minutes($candidate['lat'], $candidate['lng'], $originLat, $originLng, $mode);

        $total = $travel + $dwell + $return;

        return new ReachabilityResult(
            reachable: $total <= $remainingMinutes,
            travelMinutes: $travel,
            dwellMinutes: $dwell,
            returnMinutes: $return,
            totalMinutes: round($total, 2),
            remainingMinutes: $remainingMinutes,
        );
    }

    /**
     * Gate a candidate list. Excluded candidates are returned with their
     * breakdowns — logged, never silently dropped (E6 done-condition).
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return array{kept: list<array<string, mixed>>, excluded: list<array<string, mixed>>}
     */
    public function filter(
        array $candidates,
        float $originLat,
        float $originLng,
        TravelMode $mode,
        float $remainingMinutes,
        ?float $destLat = null,
        ?float $destLng = null,
    ): array {
        $kept = [];
        $excluded = [];

        foreach ($candidates as $candidate) {
            $result = $this->evaluate($candidate, $originLat, $originLng, $mode, $remainingMinutes, $destLat, $destLng);
            $candidate['reachability'] = $result->toTrace();

            if ($result->reachable) {
                $kept[] = $candidate;
            } else {
                $excluded[] = $candidate;
            }
        }

        return ['kept' => $kept, 'excluded' => $excluded];
    }

    /**
     * Remaining-budget depletion across feed refreshes: the budget runs down
     * with the clock from session start — a refresh two hours in gates
     * against what is actually left.
     */
    public static function remainingMinutes(DateTimeImmutable $sessionStartedAt, int $timeBudgetMinutes, DateTimeImmutable $now): float
    {
        $elapsed = ($now->getTimestamp() - $sessionStartedAt->getTimestamp()) / 60;

        return max(0.0, round($timeBudgetMinutes - $elapsed, 2));
    }
}
