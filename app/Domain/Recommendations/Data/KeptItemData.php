<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Data;

use Carbon\CarbonImmutable;

/** One row of the KEPT screen (SCREENS S6). */
final readonly class KeptItemData
{
    public function __construct(
        public string $recommendationId,
        public ?string $opportunityId,
        public string $title,
        public ?string $note,
        public float $lat,
        public float $lng,
        public CarbonImmutable $keptAt,
        public ?CarbonImmutable $windowEndsAt,
        /**
         * "Still possible" vs "Passed" — a live check against the world model, not
         * a guess from the keep. False means we can no longer stand behind it.
         */
        public bool $stillPossible,
        /** The photo. Null renders the designed paper-stripe fallback (DESIGN §3). */
        public ?array $image = null,
    ) {}
}
