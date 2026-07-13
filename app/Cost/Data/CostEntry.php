<?php

declare(strict_types=1);

namespace App\Cost\Data;

use App\Cost\Services\PriceBook;
use App\Enums\CostCategory;
use Illuminate\Support\Carbon;

/**
 * One thing that cost something (or that would have, had a cache missed).
 *
 * Deliberately dumb: units and identity only, no money. Pricing happens in
 * {@see PriceBook} at flush time, against the dated sheet then in
 * force. Keeping the price OUT of the entry is what makes the meter safe to call
 * from anywhere — a scout does not need to know what a token is worth, and a class
 * that carries a price is a class that will eventually carry a stale one.
 *
 * The correlation ids (user, trip, session…) are NOT here either: they come from the
 * meter's context at flush (COST.md §5). The Gemini client genuinely does not know
 * which user it is generating for, and it should not have to.
 */
final readonly class CostEntry
{
    public function __construct(
        public CostCategory $category,
        public string $vendor,
        public string $resource,
        public ?string $host = null,
        public ?string $model = null,
        public ?string $promptVersion = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cachedInputTokens = 0,
        public int $calls = 0,
        public int $cpuMs = 0,
        public int $peakMemKb = 0,
        /**
         * True when the work was served from a cache and therefore cost NOTHING.
         *
         * The entry still carries its units, and that is the trick: the units are what
         * the call WOULD have consumed, so PriceBook can price it as a counterfactual
         * and bill it at zero. A cache hit that recorded nothing would look identical
         * to a request that never happened, and the difference between those two is
         * precisely the value of the cache (conventions/12).
         */
        public bool $cached = false,
        public ?Carbon $occurredAt = null,
    ) {}
}
