<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Places\Data\PlaceData;
use App\Enums\AppealFacet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlaceData
 *
 * Geo-core only, so all of it is publishable — and it ships its attribution with
 * it, which is not a frontend footer but part of the payload (ODBL-REVIEW §6
 * rule 6, conventions/06).
 *
 * `source` is the seed adapter key today. The full attribution record (license,
 * URL, required notice text) is declared by the SourceRegistry, which is E5 —
 * when it lands this grows an `attribution` object and keeps the same key.
 */
final class PlaceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'location' => $this->coordinates->toArray(),
            'type' => $this->type->value,
            'type_domain' => $this->typeDomain->value,
            'facets' => array_map(fn (AppealFacet $facet): string => $facet->value, $this->facets),
            'source' => $this->source,
        ];
    }
}
