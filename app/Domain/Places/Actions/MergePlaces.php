<?php

declare(strict_types=1);

namespace App\Domain\Places\Actions;

use App\Domain\Places\Models\Place;
use App\Domain\Places\Models\PlaceMerge;
use Illuminate\Support\Facades\DB;

/**
 * Merge one canonical place into another (ENTITY-RESOLUTION §2, §5): the
 * losing id is never deleted from the world — it becomes a redirect, so user
 * data and FKs referencing it survive. Reads resolve redirects at the
 * PlaceLookup boundary.
 */
final class MergePlaces
{
    public function __invoke(Place $canonical, Place $merged): Place
    {
        return DB::transaction(function () use ($canonical, $merged): Place {
            // Contributions and audit rows move to the survivor.
            DB::table('place_source_ids')->where('place_id', $merged->id)->update(['place_id' => $canonical->id]);
            DB::table('place_match_decisions')->where('place_id', $merged->id)->update(['place_id' => $canonical->id]);

            // Field union — nothing is discarded (TAXONOMY §3).
            $canonical->alt_names = collect([...$canonical->alt_names, $merged->name, ...$merged->alt_names])
                ->filter()->unique()->reject(fn (string $n): bool => $n === $canonical->name)->values()->all();
            $canonical->facets = collect([...$canonical->facets->pluck('value')->all(), ...$merged->facets->pluck('value')->all()])
                ->unique()->values()->all();
            $canonical->source_tags = [...$merged->source_tags, ...$canonical->source_tags];
            $canonical->save();

            PlaceMerge::query()->create([
                'old_place_id' => $merged->id,
                'canonical_place_id' => $canonical->id,
                'resolver_version' => config('resolver.version'),
                'merged_at' => now(),
            ]);

            // The row goes; the id lives on in place_merges.
            DB::table('places_core')->where('id', $merged->id)->delete();

            return $canonical->refresh();
        });
    }
}
