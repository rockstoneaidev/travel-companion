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
 * The correlation ids (user, trip, session…) are not passed IN either: the caller never
 * supplies them (COST.md §5). The Gemini client genuinely does not know which user it is
 * generating for, and it should not have to.
 *
 * But the METER knows, and it stamps them on the way in — see `$correlation` below.
 * They used to be applied at FLUSH instead, once, to every entry alike, and that quietly
 * could not answer the question the ledger exists to answer. RankSession's own comment
 * promises the money "accretes to this recommendation's id from whichever process spends
 * it"; with one context per flush it accreted to whichever id happened to be set LAST,
 * so "what did this card cost?" came back zero for every card in the feed. Cost is
 * caused at the moment it is recorded, and that is the moment to write down what caused
 * it.
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
        /**
         * Who/what this spend was FOR, snapshotted by the meter at record time.
         *
         * Never set by callers — `CostMeter::record()` stamps it. Null only for entries
         * built outside the meter (tests, pricing fixtures), in which case the ledger
         * falls back to the flush-time context and behaves exactly as it used to.
         *
         * @var array<string, mixed>|null
         */
        public ?array $correlation = null,
    ) {}

    /** The meter stamping causal truth on the way in. @param array<string, mixed> $correlation */
    public function correlatedWith(array $correlation): self
    {
        return new self(
            category: $this->category,
            vendor: $this->vendor,
            resource: $this->resource,
            host: $this->host,
            model: $this->model,
            promptVersion: $this->promptVersion,
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            cachedInputTokens: $this->cachedInputTokens,
            calls: $this->calls,
            cpuMs: $this->cpuMs,
            peakMemKb: $this->peakMemKb,
            cached: $this->cached,
            occurredAt: $this->occurredAt,
            correlation: $correlation,
        );
    }
}
