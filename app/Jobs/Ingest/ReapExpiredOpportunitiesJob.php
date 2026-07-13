<?php

declare(strict_types=1);

namespace App\Jobs\Ingest;

use App\Domain\Opportunities\Actions\ReapExpiredOpportunities;
use App\Enums\QueueLane;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The nightly opportunity reap — a thin wrapper, the policy lives in the domain
 * action (conventions/08). World-model housekeeping, so it rides the serial
 * `ingest` lane like the other maintenance passes: a big DELETE must not race
 * a region build.
 */
final class ReapExpiredOpportunitiesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue(QueueLane::Ingest->value);
        $this->onConnection(QueueLane::Ingest->connection());
    }

    public function handle(ReapExpiredOpportunities $reap): void
    {
        $report = $reap();

        // Logged even when it did nothing — a reaper that is silent on success
        // is indistinguishable from one that never ran (same reasoning as the
        // retention pass).
        Log::info('opportunity reap pass', $report->toArray());
    }
}
