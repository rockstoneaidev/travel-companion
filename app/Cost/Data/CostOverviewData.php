<?php

declare(strict_types=1);

namespace App\Cost\Data;

/**
 * The /admin cost strip (docs/COST.md §7.2) — the glance-and-sleep-at-night view.
 *
 * Money crosses this boundary as MICROS, and the React side divides. Sending a
 * formatted "$1.23" would make the number un-summable in the UI; sending a float
 * would reintroduce exactly the imprecision the ledger avoids.
 */
final readonly class CostOverviewData
{
    public function __construct(
        public int $todayMicros,
        public int $monthMicros,
        public int $allTimeMicros,
        public int $dailyCapMicros,
        /** Linear burn on month-to-date. An estimate, and labelled as one in the UI. */
        public int $projectedMonthMicros,
        /** What the caches saved: Σ(would_have_billed − billed). */
        public int $savedTodayMicros,
        public bool $capReached,
        public bool $paused,
        /** The single biggest line item today: vendor + resource + micros, or null. */
        public ?array $topLineItem,
    ) {}
}
