<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cost\Services\CostRollup;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Roll up a day by hand (docs/COST.md §7.1).
 *
 * The job does this nightly. This exists for the two cases where waiting until 4am is
 * useless: filling the table the first time, and RE-rolling a day after a price sheet is
 * corrected — which is exactly when you most want the numbers to move, and the reason the
 * rollup is an idempotent upsert rather than an append.
 */
final class RollUpCostsCommand extends Command
{
    protected $signature = 'cost:rollup {--day= : YYYY-MM-DD (default: yesterday)} {--days=1 : how many days back to (re)roll}';

    protected $description = 'Roll cost_events up into cost_daily (amortised + capex-allocated)';

    public function handle(CostRollup $rollup): int
    {
        $end = $this->option('day') !== null
            ? Carbon::parse((string) $this->option('day'))
            : now()->timezone((string) config('cost.timezone'))->subDay();

        $days = max(1, (int) $this->option('days'));

        for ($i = 0; $i < $days; $i++) {
            $day = $end->copy()->subDays($i)->startOfDay();
            $rows = $rollup($day);

            $this->info("cost:rollup {$day->toDateString()} — {$rows} bucket(s)");
        }

        return self::SUCCESS;
    }
}
