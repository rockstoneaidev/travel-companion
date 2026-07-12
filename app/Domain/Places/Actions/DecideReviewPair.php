<?php

declare(strict_types=1);

namespace App\Domain\Places\Actions;

use App\Domain\Places\Models\Place;
use App\Domain\Places\Models\PlaceMatchDecision;
use Illuminate\Support\Facades\DB;

/**
 * A human settles one review-band pair (ENTITY-RESOLUTION §3 stage 4).
 *
 * Either way the decision row is stamped `decided_by = 'human'`, which both
 * removes it from the queue and preserves it as the audit trail — the resolver
 * is versioned, so we need to know which merges a machine made and which a
 * person did when we refit thresholds.
 */
final class DecideReviewPair
{
    public function __construct(private readonly MergePlaces $merge) {}

    /** The pair is the same place: merge the candidate into the compared one. */
    public function merge(PlaceMatchDecision $decision, Place $candidate, Place $compared): void
    {
        DB::transaction(function () use ($decision, $candidate, $compared): void {
            // Survivor is the place the item was compared against — it is the
            // incumbent, and its id is the one already referenced elsewhere.
            ($this->merge)($compared, $candidate);

            $this->stamp($decision, 'merged');
        });
    }

    /** The pair really is two different places. Keep both, stop asking. */
    public function keepDistinct(PlaceMatchDecision $decision): void
    {
        $this->stamp($decision, 'distinct');
    }

    private function stamp(PlaceMatchDecision $decision, string $outcome): void
    {
        $decision->forceFill([
            'decided_by' => 'human',
            'signals' => [...$decision->signals, 'human_outcome' => $outcome, 'decided_at' => now()->toIso8601String()],
        ])->save();
    }
}
