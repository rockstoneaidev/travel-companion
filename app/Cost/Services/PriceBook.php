<?php

declare(strict_types=1);

namespace App\Cost\Services;

use App\Cost\Data\CostEntry;
use App\Enums\CostCategory;

/**
 * Turns units into money, against a dated price sheet (docs/COST.md §6).
 *
 * All arithmetic is integer USD micros. No floats anywhere on this path: a float
 * cent that is out by 2^-53 is a rounding error you will find eighteen months later
 * in a reconciliation, and there is no reason to accept one when the vendor prices
 * are exact decimals.
 */
final class PriceBook
{
    private const MICROS_PER_MILLION_TOKENS = 1_000_000;

    /** The sheet key stamped onto every row this instance prices. */
    public function version(): string
    {
        return (string) config('pricing.version');
    }

    /**
     * What this entry costs, in USD micros, as if it had actually been billed.
     *
     * NOTE the "as if": a cached entry is priced exactly like an uncached one. The
     * caller decides where the number lands — a cache hit puts it in
     * `would_have_billed_usd_micros` and bills zero (see {@see CostLedger}). Pricing
     * the counterfactual is the whole reason cache savings are computable at all.
     */
    public function price(CostEntry $entry): int
    {
        // Compute is measured, never priced (COST.md §2.1). CPU is rented in fixed
        // lumps; there is no marginal price to charge, and inventing one would be
        // fake precision.
        if (! $entry->category->isBillable()) {
            return 0;
        }

        return match ($entry->category) {
            CostCategory::Llm => $this->priceLlm($entry),
            CostCategory::Api => $this->priceApi($entry),
            default => 0,
        };
    }

    /**
     * Three token classes, three rates. Summing the tokens first — which is what the
     * old CostMeter did — makes this function impossible to write.
     */
    private function priceLlm(CostEntry $entry): int
    {
        $rates = $this->sheet()['llm'][$entry->model ?? ''] ?? null;

        if ($rates === null) {
            // An unknown model prices at zero rather than throwing. A generation that
            // succeeded must not be un-done by a missing config line — but it must not
            // be silently free either, so it is logged where the ledger writes.
            return 0;
        }

        return intdiv($entry->inputTokens * (int) $rates['input'], self::MICROS_PER_MILLION_TOKENS)
            + intdiv($entry->outputTokens * (int) $rates['output'], self::MICROS_PER_MILLION_TOKENS)
            + intdiv($entry->cachedInputTokens * (int) $rates['cached_input'], self::MICROS_PER_MILLION_TOKENS);
    }

    private function priceApi(CostEntry $entry): int
    {
        $perCall = $this->sheet()['api'][$entry->resource] ?? 0;

        return $entry->calls * (int) $perCall;
    }

    /**
     * Hostname → resource slug, or null for "metered elsewhere, do not price".
     *
     * The Gemini host maps to null: GeminiClient records those calls with their real
     * token counts, and letting the global HTTP hook price them as well would
     * double-count every generation in the product's only paid path.
     */
    public function resourceForHost(string $host): ?string
    {
        $hosts = (array) config('pricing.hosts');

        if (array_key_exists($host, $hosts)) {
            return $hosts[$host];   // may legitimately be null
        }

        // Not "free" — `unknown`. A paid API that someone added without touching
        // config/pricing.php should look conspicuous in the admin, not invisible.
        return 'unknown';
    }

    public function vendorForHost(string $host): string
    {
        return (string) (config('pricing.vendors')[$host] ?? $host);
    }

    /** @return array{llm: array<string, array<string, int>>, api: array<string, int>} */
    private function sheet(): array
    {
        return (array) config('pricing.sheets.'.$this->version());
    }

    /** USD → micros, for reading the caps out of config. */
    public static function usdToMicros(float $usd): int
    {
        return (int) round($usd * 1_000_000);
    }
}
