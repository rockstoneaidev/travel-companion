<?php

declare(strict_types=1);

namespace App\Cost\Services;

use App\Cost\Data\CostEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the meter's entries to `cost_events` — once per unit of work (COST.md §5).
 *
 * ---------------------------------------------------------------------------
 *  Two rules, and they are the whole design.
 * ---------------------------------------------------------------------------
 *
 * 1. ONE STATEMENT, N ROWS. Never an insert per call. A feed request fans out to
 *    eight scouts and can make a dozen outbound calls; twelve extra round-trips to
 *    Postgres on the hot path would make the cost ledger the most expensive thing in
 *    the request it is measuring. Entries accumulate in memory, and the flush at the
 *    end of the request or job writes them in a single batched insert.
 *
 * 2. THE LEDGER NEVER TAKES DOWN THE PRODUCT. Every failure here is caught and
 *    logged. A missing partition, a Redis blip, a bad price sheet — none of them may
 *    turn a served feed into a 500. This is not defensiveness for its own sake: the
 *    alternative is a system where an accounting bug becomes an outage, and the day
 *    that happens is the day someone rips the metering out entirely. Losing a row of
 *    accounting is bad. Losing the request is worse, and it is the request the user
 *    came for.
 */
final class CostLedger
{
    public function __construct(
        private readonly PriceBook $prices,
        private readonly SpendGuard $guard,
    ) {}

    /**
     * Drain the meter, price it, write it, and book it against the caps.
     *
     * Returns the number of rows written — useful in tests, ignored in production.
     */
    public function flush(CostMeter $meter): int
    {
        $entries = $meter->drain();

        if ($entries === []) {
            return 0;
        }

        try {
            $context = $meter->context();
            $version = $this->prices->version();

            $rows = [];
            $billedTotal = 0;

            foreach ($entries as $entry) {
                $price = $this->prices->price($entry);

                // A cache hit cost nothing and would have cost $price. An uncached call
                // cost $price — and `would_have_billed` still carries it, so summing that
                // column over ALL rows gives the no-cache-at-all counterfactual, and the
                // difference between the two columns is what the caches saved
                // (conventions/12: "a product metric, not an ops metric").
                $billed = $entry->cached ? 0 : $price;

                $this->warnOnUnpricedSpend($entry, $price);

                $rows[] = [
                    // The entry's own correlation, stamped when the money was actually
                    // spent. `$context` remains the fallback for entries built outside the
                    // meter (fixtures, tests) — and for those, nothing changes.
                    ...($entry->correlation ?? $context),
                    'occurred_at' => $entry->occurredAt ?? now(),
                    'category' => $entry->category->value,
                    'vendor' => $entry->vendor,
                    'resource' => $entry->resource,
                    'host' => $entry->host,
                    'model' => $entry->model,
                    'prompt_version' => $entry->promptVersion,
                    'input_tokens' => $entry->inputTokens,
                    'output_tokens' => $entry->outputTokens,
                    'cached_input_tokens' => $entry->cachedInputTokens,
                    'calls' => $entry->calls,
                    'cpu_ms' => $entry->cpuMs,
                    'peak_mem_kb' => $entry->peakMemKb,
                    'billed_usd_micros' => $billed,
                    'would_have_billed_usd_micros' => $price,
                    'cached' => $entry->cached,
                    'price_version' => $version,
                    'created_at' => now(),
                ];

                $billedTotal += $billed;
            }

            DB::table('cost_events')->insert($rows);

            // Book AFTER the write, so the counter can never claim spend the ledger
            // does not have. The reverse ordering would drift the cap away from the
            // accounting on every failed insert.
            $this->guard->record($billedTotal, $meter->userId());

            return count($rows);
        } catch (Throwable $e) {
            // Rule 2. Loud in the log, invisible to the user.
            Log::error('cost: ledger flush failed', [
                'error' => $e->getMessage(),
                'entries' => count($entries),
            ]);

            return 0;
        }
    }

    /**
     * Spend we could not price is the one thing worth shouting about.
     *
     * An `unknown` host or a model missing from the sheet prices at zero — which is
     * the safe behaviour for the request (nothing breaks) and the DANGEROUS behaviour
     * for the books (a new paid API silently reads as free). So it is logged every
     * time, and it surfaces in the admin as `unknown` rather than being folded into
     * `free`. The failure mode we are protecting against is someone adding a paid
     * integration in six months and this system cheerfully reporting €0.00.
     */
    private function warnOnUnpricedSpend(CostEntry $entry, int $price): void
    {
        if ($price > 0 || $entry->cached) {
            return;
        }

        if ($entry->resource === 'free' || ! $entry->category->isBillable()) {
            return;   // genuinely free, or genuinely not priced (compute)
        }

        Log::warning('cost: spend recorded with no price', [
            'category' => $entry->category->value,
            'vendor' => $entry->vendor,
            'resource' => $entry->resource,
            'model' => $entry->model,
            'price_version' => $this->prices->version(),
        ]);
    }
}
