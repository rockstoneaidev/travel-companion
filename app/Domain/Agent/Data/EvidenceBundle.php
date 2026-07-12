<?php

declare(strict_types=1);

namespace App\Domain\Agent\Data;

/**
 * Everything the model is allowed to know for one generation (conventions/10,
 * PRD §12).
 *
 * The bundle is the boundary. The prompt contains the bundle and nothing else —
 * no "you know about French wine regions", because that is an invitation to
 * invent. A model that confidently describes a bakery it has never seen data for
 * is the fastest way to destroy this product, and the architecture exists to make
 * that structurally impossible rather than merely discouraged.
 *
 * The id is CONTENT-ADDRESSED: a sha256 of the items and the context. That is not
 * a detail — it is what makes the cache correct. Cache keys are
 * (prompt_version, bundle_id), so evidence changing changes the id, which
 * invalidates the generation automatically. Nothing has to remember to bust it.
 */
final readonly class EvidenceBundle
{
    /** @param list<EvidenceItem> $items */
    public function __construct(
        public array $items,
        public ContextData $context,
    ) {}

    public function id(): string
    {
        $payload = [
            'items' => array_map(static fn (EvidenceItem $i): array => [
                // Deliberately NOT retrieved_at: re-fetching identical evidence
                // must not invalidate a perfectly good generation.
                $i->source, $i->excerpt, $i->url,
            ], $this->items),
            'context' => $this->context->toArray(),
        ];

        return substr(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 0, 32);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** The evidence block of the prompt. */
    public function toPrompt(): string
    {
        return implode("\n", array_map(static fn (EvidenceItem $i): string => $i->toPrompt(), $this->items));
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (EvidenceItem $i): array => $i->toArray(), $this->items);
    }
}
