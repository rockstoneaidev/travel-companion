<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Data;

use Carbon\CarbonImmutable;

/** One row of the "Not for me" list on KEPT (SCREENS S6). */
final readonly class DismissedItemData
{
    public function __construct(
        public string $recommendationId,
        public string $title,
        public ?string $note,
        public CarbonImmutable $dismissedAt,
        /** The photo. Null renders the designed paper-stripe fallback (DESIGN §3). */
        public ?array $image = null,
    ) {}
}
