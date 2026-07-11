<?php

declare(strict_types=1);

namespace App\Domain\Curation\Actions;

use App\Domain\Curation\Enums\CurationStatus;
use App\Domain\Curation\Models\CuratedItem;
use InvalidArgumentException;

/**
 * Pipeline step 4 (CURATION §3): the human gate that turns an LLM draft into
 * Tier-A evidence. Approval requires a grounded place — approving an
 * ungrounded claim would serve a place we cannot point at.
 */
final class ReviewCuratedItem
{
    public function approve(CuratedItem $item, int $reviewerId, ?string $editedClaim = null): CuratedItem
    {
        if ($item->place_id === null) {
            throw new InvalidArgumentException('An ungrounded item cannot be approved — ground it first.');
        }

        $item->forceFill([
            'status' => CurationStatus::Approved,
            'reviewed_by' => $reviewerId,
            ...($editedClaim !== null ? ['claim' => $editedClaim, 'authored_by' => 'human'] : []),
        ])->save();

        return $item;
    }

    public function reject(CuratedItem $item, int $reviewerId): CuratedItem
    {
        $item->forceFill(['status' => CurationStatus::Rejected, 'reviewed_by' => $reviewerId])->save();

        return $item;
    }
}
