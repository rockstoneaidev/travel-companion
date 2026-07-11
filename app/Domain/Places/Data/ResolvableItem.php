<?php

declare(strict_types=1);

namespace App\Domain\Places\Data;

use App\Enums\CredibilityTier;

/**
 * A source item as the resolver sees it — Places' own view of the data, so
 * the resolver never touches the Sources module's internals (conventions/01:
 * cross-module traffic goes through Contracts and Data).
 */
final readonly class ResolvableItem
{
    /** @param array<string, mixed> $payload the shared candidate format (conventions/09) */
    public function __construct(
        public string $id,
        public string $source,
        public ?string $externalId,
        public CredibilityTier $credibilityTier,
        public ?string $h3Index,
        public array $payload,
    ) {}

    public function candidate(): ResolutionCandidate
    {
        return ResolutionCandidate::fromPayload($this->payload);
    }

    public function wikidataQid(): ?string
    {
        return $this->source === 'wikidata'
            ? $this->externalId
            : ($this->payload['external_refs']['wikidata'] ?? null);
    }
}
