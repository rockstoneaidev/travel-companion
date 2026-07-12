<?php

declare(strict_types=1);

namespace App\Domain\Places\Contracts;

use App\Domain\Places\Data\ResolvableItem;

/**
 * What the resolver needs from the Sources module (conventions/01 boundary):
 * open-core candidates, in deterministic credibility order, without a
 * decision at the given resolver version.
 */
interface ResolvableItems
{
    /** @return list<ResolvableItem> */
    public function unresolvedInTile(string $h3Index, string $resolverVersion): array;

    public function find(string $source, string $externalId): ?ResolvableItem;

    /**
     * Every open-core item inside a bbox — the gold-set sampler's view of a
     * region (ENTITY-RESOLUTION §6). Unlike unresolvedInTile() this ignores
     * whether an item has been decided: the gold set measures the resolver, so
     * it must see what the resolver already acted on.
     *
     * @return list<ResolvableItem>
     */
    public function inBoundingBox(float $south, float $west, float $north, float $east): array;
}
