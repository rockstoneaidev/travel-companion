<?php

declare(strict_types=1);

namespace App\Domain\Curation\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The curated-item lifecycle (CURATION §2–3). The review gate is the whole
 * point: an unreviewed draft is NEVER served — serving one would launder LLM
 * text into the Tier-A evidence chain (conventions/10).
 */
enum CurationStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case NeedsGrounding = 'needs_grounding';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Archived = 'archived';

    public function isServeable(): bool
    {
        return $this === self::Approved;
    }
}
