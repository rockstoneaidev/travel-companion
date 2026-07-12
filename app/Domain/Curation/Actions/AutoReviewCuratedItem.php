<?php

declare(strict_types=1);

namespace App\Domain\Curation\Actions;

use App\Domain\Curation\Enums\CurationStatus;
use App\Domain\Curation\Models\CuratedItem;

/**
 * Policy: what a verdict MEANS (CURATION §4).
 *
 * Kept apart from VerifyCuratedItem on purpose. Verification is a measurement;
 * approval is a decision. Splitting them is what lets us run the verifier over the
 * 149 items a human already approved and learn whether the machine agrees — without
 * the act of measuring changing a single row's status.
 *
 * The policy itself is deliberately timid:
 *
 *   · every assertion supported  → approved, and the verdict is on the row
 *   · anything else              → in_review, for a human
 *
 * It never REJECTS. A model that says "unsupported" has found a question, not an
 * answer: the claim may be fine and the evidence merely thin, and a rejection would
 * throw away a place we may have no other way to describe. So the machine's power is
 * asymmetric — it can wave things through, it can only ever escalate the rest.
 */
final class AutoReviewCuratedItem
{
    public function __construct(private readonly VerifyCuratedItem $verify) {}

    /** @return array{status: CurationStatus, verdict: array<string, mixed>} */
    public function __invoke(CuratedItem $item): array
    {
        $verdict = ($this->verify)($item);

        $status = ($verdict['supported'] ?? false) === true
            ? CurationStatus::Approved
            : CurationStatus::InReview;

        $item->forceFill([
            'status' => $status,
            // reviewed_by stays NULL: it is a foreign key to users, and no user did
            // this. `verifier_version` on the row is what says who did — a machine
            // approval must never be indistinguishable from a person's.
            'reviewed_by' => null,
        ])->save();

        return ['status' => $status, 'verdict' => $verdict];
    }
}
