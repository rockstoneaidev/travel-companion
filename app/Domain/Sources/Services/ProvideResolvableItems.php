<?php

declare(strict_types=1);

namespace App\Domain\Sources\Services;

use App\Domain\Places\Contracts\ResolvableItems;
use App\Domain\Places\Data\ResolvableItem;
use App\Domain\Sources\Models\SourceItem;

/**
 * Sources' side of the resolver boundary: serves its rows to Places as DTOs
 * (conventions/01 — the SourceItem model never leaves this module).
 */
final class ProvideResolvableItems implements ResolvableItems
{
    public function unresolvedInTile(string $h3Index, string $resolverVersion): array
    {
        $tierRank = "CASE credibility_tier WHEN 'official' THEN 0 WHEN 'reference' THEN 1 WHEN 'open' THEN 2 ELSE 3 END";

        return SourceItem::query()
            ->where('h3_index', $h3Index)
            ->whereNotExists(function ($query) use ($resolverVersion): void {
                $query->selectRaw('1')
                    ->from('place_match_decisions')
                    ->whereColumn('place_match_decisions.source_item_id', 'source_items.id')
                    ->where('place_match_decisions.resolver_version', $resolverVersion);
            })
            ->orderByRaw($tierRank)
            ->orderBy('source')
            ->orderBy('external_id')
            ->get()
            ->map(self::toData(...))
            ->all();
    }

    public function find(string $source, string $externalId): ?ResolvableItem
    {
        $item = SourceItem::query()
            ->where('source', $source)
            ->where('external_id', $externalId)
            ->first();

        return $item === null ? null : self::toData($item);
    }

    private static function toData(SourceItem $item): ResolvableItem
    {
        return new ResolvableItem(
            id: $item->id,
            source: $item->source,
            externalId: $item->external_id,
            credibilityTier: $item->credibility_tier,
            h3Index: $item->h3_index,
            payload: $item->payload,
        );
    }
}
