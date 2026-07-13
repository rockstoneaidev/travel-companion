<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the `cost_events` partition window rolling ahead of the clock (COST.md §5).
 *
 * A partitioned table with no partition for today's date REJECTS the insert. The
 * ledger writer swallows that (a failed flush is logged, never thrown — an accounting
 * bug must not become an outage), so the failure mode is silent: spend stops being
 * recorded and nothing visibly breaks. That is the worst possible shape for a bug in
 * a cost system, so this runs monthly AND on every deploy, and it is idempotent.
 *
 * Six months of headroom means the alarm you would need to miss is six months long.
 */
final class EnsureCostPartitionsCommand extends Command
{
    protected $signature = 'cost:partitions {--months=6 : How many months ahead to guarantee}';

    protected $description = 'Create the cost_events monthly partitions ahead of the clock';

    public function handle(): int
    {
        $months = (int) $this->option('months');
        $created = [];

        for ($i = 0; $i <= $months; $i++) {
            $month = now()->startOfMonth()->addMonths($i);

            if ($this->create($month)) {
                $created[] = $month->format('Y-m');
            }
        }

        $this->info($created === []
            ? "cost_events: partitions already cover the next {$months} months"
            : 'cost_events: created '.implode(', ', $created));

        return self::SUCCESS;
    }

    /** @return bool true if this call created the partition */
    private function create(Carbon $month): bool
    {
        $name = 'cost_events_'.$month->format('Y_m');

        $exists = DB::selectOne('SELECT to_regclass(?) AS t', [$name])->t !== null;

        if ($exists) {
            return false;
        }

        DB::statement(sprintf(
            "CREATE TABLE IF NOT EXISTS %s PARTITION OF cost_events FOR VALUES FROM ('%s') TO ('%s')",
            $name,
            $month->format('Y-m-d'),
            $month->copy()->addMonth()->format('Y-m-d'),
        ));

        return true;
    }
}
