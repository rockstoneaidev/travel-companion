<?php

declare(strict_types=1);

namespace App\Domain\Curation\Actions;

use App\Domain\Curation\Enums\CurationStatus;
use App\Domain\Curation\Models\CuratedItem;
use App\Domain\Curation\Services\ClaimGuard;
use InvalidArgumentException;

/**
 * Pipeline step 4 (CURATION §3): the human gate that turns an LLM draft into
 * Tier-A evidence. Approval requires a grounded place — approving an
 * ungrounded claim would serve a place we cannot point at.
 */
final class ReviewCuratedItem
{
    public function __construct(private readonly ClaimGuard $guard) {}

    public function approve(CuratedItem $item, int $reviewerId, ?string $editedClaim = null): CuratedItem
    {
        if ($item->place_id === null) {
            throw new InvalidArgumentException('An ungrounded item cannot be approved — ground it first.');
        }

        $claim = $editedClaim ?? (string) $item->claim;

        /*
         * ===================================================================
         *  A HUMAN CANNOT APPROVE A PRICE INTO THE PRODUCT.
         * ===================================================================
         *
         * ClaimGuard's docblock promised that "a draft that names a price does not reach
         * a traveller no matter how well the verifier likes its prose". That was only
         * true of the VERIFIER's path. This one — the human clicking Approve — never
         * consulted the guard at all, so the enforcement had a door in it, and the door
         * was the button the reviewer is invited to press on every item.
         *
         * The perishable sentence is now CUT, not grounds for refusal. Rejecting the item
         * would throw away the place along with the sentence — a bistro nobody else will
         * tell you about, lost over a coffee price we were never allowed to say. So the
         * clause goes and the claim stands.
         *
         * If nothing survives the cut, the "claim" was only ever a price, and there is
         * nothing here to approve.
         */
        $trimmed = $this->guard->trimPerishable($claim);

        if ($trimmed === '') {
            throw new InvalidArgumentException(
                'Nothing is left of this claim once the perishable facts (hours, prices) are removed — reject it instead.',
            );
        }

        // A trim is an EDIT, and an edited claim is authored by the human who approved it.
        // Attribution follows the last hand on the text, not the first.
        $edited = $trimmed !== (string) $item->claim;

        $item->forceFill([
            'status' => CurationStatus::Approved,
            'reviewed_by' => $reviewerId,
            ...($edited ? ['claim' => $trimmed, 'authored_by' => 'human'] : []),
        ])->save();

        return $item;
    }

    public function reject(CuratedItem $item, int $reviewerId): CuratedItem
    {
        $item->forceFill(['status' => CurationStatus::Rejected, 'reviewed_by' => $reviewerId])->save();

        return $item;
    }
}
