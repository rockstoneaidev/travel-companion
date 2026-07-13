<?php

declare(strict_types=1);

namespace App\Jobs\Cost;

use App\Cost\Services\CostRollup;
use App\Enums\QueueLane;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The nightly rollup (docs/COST.md §7.1). A thin wrapper — the allocation model lives
 * in the domain service, not in a job (conventions/08).
 *
 * Rolls YESTERDAY by default, not today: a day that is still happening produces a
 * partial row that the next run has to correct, and a rollup that is sometimes wrong in
 * a way nobody can see is worse than one that is always a day behind in a way everyone
 * expects. The /admin strip reads the live ledger for "today" precisely so that this
 * job never has to.
 */
final class RollUpCostsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public readonly ?string $day = null)
    {
        $this->onQueue(QueueLane::Ingest->value);
        $this->onConnection(QueueLane::Ingest->connection());
    }

    public function handle(CostRollup $rollup): void
    {
        $day = $this->day !== null
            ? Carbon::parse($this->day)
            : now()->timezone((string) config('cost.timezone'))->subDay();

        $rows = $rollup($day->startOfDay());

        Log::info('cost rollup', [
            'day' => $day->toDateString(),
            'buckets' => $rows,
            'price_version' => config('pricing.version'),
        ]);
    }
}
