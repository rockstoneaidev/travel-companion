<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Data;

use Carbon\CarbonImmutable;

/** One line of the digest — a serif title, a window, a one-line note (SCREENS S8). */
final readonly class DigestItem
{
    public function __construct(
        public string $opportunityId,
        public string $title,
        public ?string $note,
        public ?CarbonImmutable $windowEndsAt,
        /** Why it never made the feed — "held" or "outranked". Shown to nobody, kept for the trace. */
        public string $reason,
        /** The photo. A digest without pictures is a to-do list. */
        public ?array $image = null,
    ) {}
}
