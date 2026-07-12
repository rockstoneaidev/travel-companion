<?php

declare(strict_types=1);

namespace App\Domain\Curation\Actions;

use App\Domain\Curation\Enums\CurationStatus;
use App\Domain\Curation\Models\Pack;

/** Pipeline step 5 (CURATION §3): version bump; approved items become CuratedScout candidates. */
final class PublishPack
{
    /**
     * @param  int  $effortMinutes  founder review time spent on this version.
     *                              Accumulates: it feeds the Phase-3 cost model
     *                              (CURATION §5), where the question is what a
     *                              region actually costs in human hours.
     */
    public function __invoke(Pack $pack, int $effortMinutes = 0): Pack
    {
        $pack->forceFill([
            'status' => 'published',
            'pack_version' => $pack->pack_version + 1,
            'effort_minutes' => $pack->effort_minutes + max(0, $effortMinutes),
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

    /** How many approved items a pack has — the only thing publishing actually ships. */
    public function approvedCount(Pack $pack): int
    {
        return $pack->items()->where('status', CurationStatus::Approved)->count();
    }
}
