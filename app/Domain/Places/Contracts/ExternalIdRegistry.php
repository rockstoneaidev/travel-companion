<?php

declare(strict_types=1);

namespace App\Domain\Places\Contracts;

/**
 * The cross-source ID concordance, as other modules may see it (conventions/01).
 *
 * Places owns `place_source_ids`. Context needs a Google place_id to verify hours
 * with, and it may hold that STRING — but it may not reach into another module's
 * Eloquent models to get it (the arch test enforces this, and caught me doing it).
 *
 * Keeping it behind a contract also puts the collision rule where it belongs: in
 * the module that owns the uniqueness constraint, not in every caller that might
 * trip over it.
 */
interface ExternalIdRegistry
{
    /** The external id we have already recorded for this place from this source. */
    public function externalIdFor(string $placeId, string $source): ?string;

    /**
     * Record an external id, unless another place has already claimed it.
     *
     * Returns false on collision rather than throwing or overwriting: one external
     * entity is one real place, so a collision means one of the two mappings is
     * wrong and we do not know which. The caller's job is then to not trust it —
     * not to crash, and not to steal it.
     */
    public function remember(string $placeId, string $source, string $externalId): bool;
}
