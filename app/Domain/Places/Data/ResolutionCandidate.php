<?php

declare(strict_types=1);

namespace App\Domain\Places\Data;

use App\Domain\Places\Enums\PlaceType;

/**
 * What the resolver compares: names (all of them), a point, and the taxonomy
 * projection (ENTITY-RESOLUTION §3 stage 0). Built from a source item's
 * candidate payload or from an existing canonical place.
 */
final readonly class ResolutionCandidate
{
    /** @param list<string> $names raw primary + alternates, originals preserved */
    public function __construct(
        public array $names,
        public float $lat,
        public float $lng,
        public ?PlaceType $type,
    ) {}

    /** @param array<string, mixed> $payload the shared candidate format (conventions/09) */
    public static function fromPayload(array $payload): self
    {
        return new self(
            names: array_values(array_filter([$payload['name'] ?? null, ...($payload['alt_names'] ?? [])])),
            lat: (float) $payload['lat'],
            lng: (float) $payload['lng'],
            type: isset($payload['type']) && $payload['type'] !== null ? PlaceType::from($payload['type']) : null,
        );
    }
}
