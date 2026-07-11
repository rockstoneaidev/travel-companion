<?php

declare(strict_types=1);

namespace App\Domain\Places\Actions;

use App\Domain\Places\Contracts\ResolvableItems;
use App\Domain\Places\Enums\MatchBand;
use App\Domain\Places\Models\Place;
use App\Domain\Places\Models\PlaceMatchDecision;
use App\Domain\Places\Models\PlaceSourceId;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Un-merge (ENTITY-RESOLUTION §5): split a source's contribution out of a
 * place into a new one, recorded as a reviewer decision. Any redirect that
 * pointed at the old id keeps working — redirects are never removed.
 */
final class SplitPlace
{
    public function __construct(
        private readonly ResolveSourceItem $resolve,
        private readonly ResolvableItems $items,
    ) {}

    public function __invoke(Place $place, string $source, string $externalId): Place
    {
        return DB::transaction(function () use ($place, $source, $externalId): Place {
            $sourceId = PlaceSourceId::query()
                ->where('place_id', $place->id)
                ->where('source', $source)
                ->where('external_id', $externalId)
                ->firstOrFail();

            $item = $this->items->find($source, $externalId) ?? throw new InvalidArgumentException(
                "No source item {$source}/{$externalId} to rebuild the split place from.",
            );

            $sourceId->delete();

            $split = $this->resolve->asNewPlace($item, MatchBand::Distinct, $place, null, ['split_from' => $place->id]);

            PlaceMatchDecision::query()
                ->where('source_item_id', $item->id)
                ->latest('created_at')
                ->first()
                ?->update(['decided_by' => 'reviewer']);

            return $split;
        });
    }
}
