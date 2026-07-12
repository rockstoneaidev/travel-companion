<?php

declare(strict_types=1);

namespace App\Domain\Agent\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Which model to spend on (PRD Appendix A). Two tiers, chosen per generation —
 * a card summary is not worth what a pack draft is worth.
 */
enum LlmTier: string
{
    use HasOptions;

    /** Bulk work: card summaries, facet tagging. Cheap enough to run per opportunity. */
    case Cheap = 'cheap';

    /** Judgement work: pack drafts, the editorial lede. Fewer calls, better prose. */
    case Capable = 'capable';

    public function model(): string
    {
        return (string) config("services.gemini.models.{$this->value}");
    }
}
