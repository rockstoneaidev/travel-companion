<?php

declare(strict_types=1);

namespace App\Jobs\Privacy;

use App\Domain\Privacy\Actions\CoarsenExpiredLocations;
use App\Domain\Privacy\Actions\CoarsenExpiredTraces;
use App\Domain\Privacy\Actions\DeidentifyCostEvents;
use App\Enums\QueueLane;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The nightly retention pass (PRD §16). A thin wrapper — the policy lives in the
 * domain actions, not in a job (conventions/08).
 *
 * Runs whether or not anyone is watching, which is the point: a retention policy
 * that depends on somebody remembering to run it is not a policy.
 */
final class EnforceRetentionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue(QueueLane::Ingest->value);
        $this->onConnection(QueueLane::Ingest->connection());
    }

    public function handle(
        CoarsenExpiredLocations $locations,
        CoarsenExpiredTraces $traces,
        DeidentifyCostEvents $costEvents,
    ): void {
        $raw = $locations();
        $trace = $traces();

        // The cost ledger ages out its PERSON, not its money (COST.md §10). Same
        // schedule discipline as everything else here: on a timer, whether or not
        // anyone remembers this table holds personal data.
        $cost = $costEvents();

        // Logged, always — including the nights it did nothing. A retention job that
        // is silent when it succeeds is indistinguishable from one that never ran,
        // and "we can show it ran" is what accountability means (GDPR Art. 5).
        Log::info('privacy retention pass', [
            'policy_version' => config('privacy.version'),
            'retention_days' => config('privacy.raw_location_retention_days'),
            ...$raw->toArray(),
            'traces' => $trace->traces,
            'cost_events_deidentified' => $cost,
        ]);
    }
}
