<?php

declare(strict_types=1);

namespace App\Domain\Curation\Actions;

use App\Domain\Curation\Enums\CurationStatus;
use App\Domain\Curation\Models\Pack;

/** Pipeline step 5 (CURATION §3): version bump; approved items become CuratedScout candidates. */
final class PublishPack
{
    public function __invoke(Pack $pack): Pack
    {
        $pack->forceFill([
            'status' => 'published',
            'pack_version' => $pack->pack_version + 1,
            'h3_set' => $pack->items()
                ->where('status', CurationStatus::Approved)
                ->join('places_core', 'places_core.id', '=', 'curated_items.place_id')
                ->pluck('places_core.h3_index')
                ->unique()
                ->values()
                ->all(),
        ])->save();

        return $pack;
    }
}
