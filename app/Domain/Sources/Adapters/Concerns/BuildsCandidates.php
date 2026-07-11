<?php

declare(strict_types=1);

namespace App\Domain\Sources\Adapters\Concerns;

use App\Domain\Places\Enums\PlaceType;
use App\Domain\Places\Taxonomy\Taxonomy;
use App\Enums\AppealFacet;

/**
 * The shared candidate format every normalize() produces (conventions/09,
 * ENTITY-RESOLUTION §2): names (all of them — never pick one "true" name at
 * ingest), point, typed taxonomy projection, raw source tags, external refs
 * for Stage-1 explicit-ID joins.
 */
trait BuildsCandidates
{
    /**
     * @param  array<string, mixed>  $sourceTags
     * @param  array<string, string>  $externalRefs
     * @param  list<string>  $altNames
     * @return array<string, mixed>
     */
    private function candidate(
        string $externalId,
        string $name,
        array $altNames,
        float $lat,
        float $lng,
        ?PlaceType $type,
        array $sourceTags,
        array $externalRefs,
        string $language,
    ): array {
        return [
            'external_id' => $externalId,
            'name' => $name,
            'alt_names' => array_values(array_unique(array_filter($altNames, fn (string $n): bool => $n !== '' && $n !== $name))),
            'lat' => $lat,
            'lng' => $lng,
            'type' => $type?->value,
            'type_domain' => $type?->domain()->value,
            'facets' => $type === null ? [] : array_map(fn (AppealFacet $f): string => $f->value, $type->baseFacets()),
            'source_tags' => $sourceTags,
            'external_refs' => $externalRefs,
            'language' => $language,
            'taxonomy_version' => Taxonomy::VERSION,
        ];
    }
}
