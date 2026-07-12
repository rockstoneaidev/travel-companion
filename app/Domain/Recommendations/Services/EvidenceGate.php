<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Services;

use App\Domain\Recommendations\Data\ScoringModel;
use App\Enums\CredibilityTier;

/**
 * The two evidence gates at Decide (SCORING §2.1, PRD §10 step 10).
 *
 * These are *membership* rules, not soft scores — exactly like the reachability
 * gate. A candidate that fails either one is never served, however well it
 * scores; low confidence must not merely sink an item down the feed.
 *
 *   1. confidence < 0.25            → held (WATCHING / digest)
 *   2. Tier-D-only evidence         → a lead, not an item: routed to the
 *                                     corroboration queue, surfaced only once a
 *                                     non-Tier-D source establishes it exists.
 *
 * Pure and side-effect free: given scored candidates it returns the partition,
 * so the replayer can recompute Decide from a trace (SCORING §2.2).
 */
final readonly class EvidenceGate
{
    public function __construct(private ScoringModel $model) {}

    /**
     * @param  list<array<string, mixed>>  $scored  candidates carrying sub_scores + tiers
     * @return array{served: list<array<string, mixed>>, held: list<array<string, mixed>>}
     */
    public function partition(array $scored): array
    {
        $served = [];
        $held = [];

        foreach ($scored as $candidate) {
            $reason = $this->holdReason($candidate);

            if ($reason === null) {
                $served[] = $candidate;

                continue;
            }

            $held[] = [...$candidate, 'hold' => $reason];
        }

        return ['served' => $served, 'held' => $held];
    }

    /**
     * Why this candidate may not be served — or null if it clears both gates.
     *
     * @param  array<string, mixed>  $candidate
     * @return array{reason: string, status: string, confidence: float, tiers: list<string>}|null
     */
    private function holdReason(array $candidate): ?array
    {
        /** @var list<string> $tiers */
        $tiers = $candidate['tiers'] ?? [];
        $confidence = (float) ($candidate['sub_scores']['confidence'] ?? 0.0);

        $trace = ['confidence' => $confidence, 'tiers' => $tiers];

        // Nothing is asserting this place exists at all. Distinct from Tier-D-only:
        // there is no lead to corroborate, so there is nothing to queue.
        if ($tiers === []) {
            return [...$trace, 'reason' => 'no_evidence', 'status' => 'watching'];
        }

        // Gate 2 before gate 1: a D-only candidate is a lead, and saying so is
        // more useful than "low confidence" — which it also is, via the 0.40 cap.
        if (! $this->hasCorroboration($tiers)) {
            return [...$trace, 'reason' => 'tier_d_only', 'status' => 'corroboration_queue'];
        }

        if ($confidence < $this->confidenceFloor()) {
            return [...$trace, 'reason' => 'below_confidence_floor', 'status' => 'watching'];
        }

        return null;
    }

    /**
     * At least one source that can establish existence (DATA-SOURCES §1.2).
     * An unrecognised source name is treated as Tier-D: it may not establish
     * existence until someone classifies it in the SourceRegistry.
     *
     * @param  list<string>  $tiers
     */
    private function hasCorroboration(array $tiers): bool
    {
        foreach ($tiers as $tier) {
            if ((CredibilityTier::tryFrom($tier) ?? CredibilityTier::Community)->canEstablishExistence()) {
                return true;
            }
        }

        return false;
    }

    private function confidenceFloor(): float
    {
        return (float) $this->model->decide['confidence_floor'];
    }
}
