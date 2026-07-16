<?php

declare(strict_types=1);

namespace App\Jobs\Trips;

use App\Domain\Trips\Actions\ExpireStaleSessions;
use App\Enums\QueueLane;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The session reaper on a schedule — a thin wrapper, the policy lives in the domain action
 * (conventions/08). Light housekeeping (a handful of row updates and their end-of-session
 * events), so it rides the default lane rather than the serial `ingest` one; it has no big
 * table to race.
 */
final class ReapStaleSessionsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue(QueueLane::Default->value);
        $this->onConnection(QueueLane::Default->connection());
    }

    public function handle(ExpireStaleSessions $expire): void
    {
        $count = $expire();

        // Logged even at zero — a reaper silent on success is indistinguishable from one
        // that never ran (same reasoning as the retention and opportunity passes).
        Log::info('stale session reap pass', ['expired' => $count]);
    }
}
