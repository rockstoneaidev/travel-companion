<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Data;

/**
 * The daily digest (PRD §12.4, SCREENS S8).
 *
 * The release valve. Opportunities that don't clear the feed bar don't die — they
 * surface here, which lowers the pressure on every individual interrupt decision
 * and gives the learning loop far more labelled exposure than a 5-item feed ever
 * could. In Phase 1 there is no push: it is a screen you find, not a tap on the
 * shoulder.
 */
final readonly class DigestData
{
    /**
     * @param  list<DigestItem>  $items
     */
    public function __construct(
        public string $variant,        // morning | evening
        public string $lede,
        public string $subline,
        public array $items,
        public ?string $tripId = null,
        public ?string $tripName = null,
        public int $visitedToday = 0,
        public int $keptToday = 0,
    ) {}
}
