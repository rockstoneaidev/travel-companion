<?php

declare(strict_types=1);

namespace App\Domain\Curation\Data;

/**
 * A place selected as worth a curator's attention (CURATION §4).
 *
 * Already canonical — it came OUT of places_core — so a draft made from it is
 * grounded by construction. That is the difference between this path and the
 * harvest-file path: there is no fuzzy re-matching step left to get wrong, and
 * no draft can land in the queue attached to the wrong place.
 */
final readonly class PackCandidate
{
    /** @param list<string> $facets */
    public function __construct(
        public string $placeId,
        public string $name,
        public string $type,
        public array $facets,
    ) {}
}
