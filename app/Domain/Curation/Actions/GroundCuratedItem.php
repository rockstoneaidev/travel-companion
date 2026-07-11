<?php

declare(strict_types=1);

namespace App\Domain\Curation\Actions;

use App\Domain\Curation\Enums\CurationStatus;
use App\Domain\Curation\Models\CuratedItem;
use App\Domain\Places\Contracts\PlaceLookup;

/**
 * Pipeline step 3 (CURATION §3): link a draft to a canonical place. A match
 * moves it to review; no match → needs_grounding (a human finds/creates the
 * place or rejects).
 */
final class GroundCuratedItem
{
    public function __construct(
        private readonly PlaceLookup $places,
    ) {}

    public function __invoke(CuratedItem $item): CuratedItem
    {
        $match = $this->places->searchByName($item->title, $item->region_slug);

        $item->forceFill($match === null
            ? ['status' => CurationStatus::NeedsGrounding]
            : ['place_id' => $match->id, 'status' => CurationStatus::InReview],
        )->save();

        return $item;
    }
}
