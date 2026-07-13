<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Http\Harvest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Does the world still charge what our price sheet says? (docs/COST.md §6.1)
 *
 * ---------------------------------------------------------------------------
 *  This command NEVER writes a price. That is the entire design.
 * ---------------------------------------------------------------------------
 *
 * It is tempting to wire a live price feed straight into the ledger and never think
 * about prices again. Do not. A ledger repriced by a third-party feed that moved
 * underneath it is not an audit trail — last month's spend would silently become a
 * different number, and `price_version` (which exists precisely to make that
 * impossible) would be a lie. The community aggregators are also occasionally wrong,
 * and they are wrongest on exactly the rates that matter here: cached input and batch.
 *
 * So the loop is: this command compares, and a HUMAN lands a new dated sheet in
 * config/pricing.php. Verification is automated; repricing stays deliberate.
 *
 * Source: LiteLLM's `model_prices_and_context_window.json` — the de-facto community
 * standard, free, no key. Google's Cloud Billing Pricing API is the authoritative
 * source for the Maps SKUs and is the natural second source; it needs an API key and a
 * SKU-id walk, so it is deliberately left as a follow-up rather than half-done here.
 */
final class CheckPriceDriftCommand extends Command
{
    protected $signature = 'cost:price-drift';

    protected $description = 'Compare config/pricing.php against upstream vendor prices and report drift';

    private const LITELLM_URL = 'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json';

    public function handle(Harvest $harvest): int
    {
        $version = (string) config('pricing.version');
        $ours = (array) config("pricing.sheets.{$version}.llm");

        try {
            // The INGEST lane's HTTP policy: retry with back-off, because nobody is waiting
            // and a silently-skipped check is worse than a slow one (Harvest's docblock —
            // retry policy follows the LANE, not the vendor).
            $result = $harvest->get(self::LITELLM_URL, timeout: 20);

            if (! $result->ok() || $result->response === null) {
                throw new \RuntimeException($result->reason ?? 'upstream unavailable');
            }

            $upstream = (array) $result->response->json();
        } catch (Throwable $e) {
            $this->error('price drift: could not reach the upstream feed — '.$e->getMessage());
            Log::warning('cost: price drift check failed', ['error' => $e->getMessage()]);

            // NOT a failure exit: an unreachable feed is not a pricing problem, and a red
            // scheduled task that means "GitHub was slow" is a red task people learn to
            // ignore — including on the day it means something.
            return self::SUCCESS;
        }

        $drift = [];

        foreach ($ours as $model => $rates) {
            $upstreamModel = $upstream["gemini/{$model}"] ?? $upstream[$model] ?? null;

            if ($upstreamModel === null) {
                $drift[] = ['model' => $model, 'field' => '*', 'ours' => 'set', 'theirs' => 'MODEL NOT FOUND'];

                continue;
            }

            // LiteLLM quotes USD per token as a float; we hold USD micros per 1M tokens.
            // 1 token at $X → X * 1e6 micros per million... which is X * 1e6 * 1e6 / 1e6.
            $map = [
                'input' => 'input_cost_per_token',
                'output' => 'output_cost_per_token',
                'cached_input' => 'cache_read_input_token_cost',
            ];

            foreach ($map as $field => $key) {
                if (! isset($upstreamModel[$key])) {
                    continue;   // the feed does not carry it; silence is not evidence of change
                }

                $theirs = (int) round((float) $upstreamModel[$key] * 1_000_000 * 1_000_000);
                $oursMicros = (int) ($rates[$field] ?? 0);

                if ($theirs !== $oursMicros) {
                    $drift[] = [
                        'model' => $model,
                        'field' => $field,
                        'ours' => $oursMicros,
                        'theirs' => $theirs,
                    ];
                }
            }
        }

        // Cached for the admin control panel, so the operator can see at a glance whether
        // every number on the page is priced from a sheet the world still agrees with.
        Cache::put('cost:price-drift', [
            'checked_at' => now()->toIso8601String(),
            'price_version' => $version,
            'drift' => $drift,
        ], now()->addDays(30));

        if ($drift === []) {
            $this->info("price drift: none — sheet {$version} matches upstream");

            return self::SUCCESS;
        }

        $this->warn("price drift: {$version} disagrees with upstream on ".count($drift).' rate(s)');
        $this->table(['model', 'field', 'ours (µ/1M)', 'upstream (µ/1M)'], array_map(
            fn (array $d): array => [$d['model'], $d['field'], $d['ours'], $d['theirs']],
            $drift,
        ));
        $this->line('Land a NEW dated sheet in config/pricing.php. Never edit an existing one (COST.md §2.4).');

        Log::warning('cost: price sheet has drifted from upstream', ['price_version' => $version, 'drift' => $drift]);

        return self::SUCCESS;
    }
}
