<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Domain\Places\Contracts\ExternalIdRegistry;
use App\Domain\Places\Models\PlaceSourceId;
use Illuminate\Support\Facades\Log;

/** Places' implementation of the concordance contract (conventions/01). */
final class PlaceExternalIds implements ExternalIdRegistry
{
    public function externalIdFor(string $placeId, string $source): ?string
    {
        $id = PlaceSourceId::query()
            ->where('place_id', $placeId)
            ->where('source', $source)
            ->value('external_id');

        return $id === null ? null : (string) $id;
    }

    public function remember(string $placeId, string $source, string $externalId): bool
    {
        /*
         * `place_source_ids` is unique on (source, external_id), and rightly so: one
         * external entity is one real place. A collision therefore means somebody
         * matched wrong — a fuzzy text search hit the neighbouring café, or our own
         * world model holds a duplicate. Either way one of the two mappings is bad
         * and we cannot tell which.
         *
         * Overwriting would silently steal the mapping. Letting the insert throw
         * would kill whatever request happened to be holding it — for Google hours
         * verification, an entire feed, because one search picked the wrong café.
         * So: refuse, report, and let the caller distrust the match.
         */
        $claimed = PlaceSourceId::query()
            ->where('source', $source)
            ->where('external_id', $externalId)
            ->where('place_id', '!=', $placeId)
            ->exists();

        if ($claimed) {
            Log::info('external id already claimed by another place', [
                'place_id' => $placeId,
                'source' => $source,
                'external_id' => $externalId,
            ]);

            return false;
        }

        PlaceSourceId::query()->updateOrCreate(
            ['place_id' => $placeId, 'source' => $source],
            ['external_id' => $externalId],
        );

        return true;
    }
}
