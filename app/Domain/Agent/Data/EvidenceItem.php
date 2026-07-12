<?php

declare(strict_types=1);

namespace App\Domain\Agent\Data;

use App\Enums\CredibilityTier;
use App\Enums\SourceLicense;
use Carbon\CarbonImmutable;

/**
 * One piece of evidence the model is allowed to see (conventions/10).
 *
 * Everything the model may say has to trace back to one of these. The excerpt is
 * what somebody else wrote and we are licensed to hold; it is never something we
 * asserted, and never something the model recalled.
 */
final readonly class EvidenceItem
{
    public function __construct(
        public string $source,
        public SourceLicense $license,
        public CredibilityTier $credibilityTier,
        public string $excerpt,
        public ?string $url,
        public ?string $attribution,
        public CarbonImmutable $retrievedAt,
    ) {}

    /** What the model actually sees — no ids, no internals, just the claim and who made it. */
    public function toPrompt(): string
    {
        return sprintf('- [%s] %s', $this->source, trim($this->excerpt));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'license' => $this->license->value,
            'credibility_tier' => $this->credibilityTier->value,
            'excerpt' => $this->excerpt,
            'url' => $this->url,
            'attribution' => $this->attribution,
            'retrieved_at' => $this->retrievedAt->toIso8601String(),
        ];
    }
}
