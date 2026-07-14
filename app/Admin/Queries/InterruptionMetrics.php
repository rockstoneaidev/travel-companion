<?php

declare(strict_types=1);

namespace App\Admin\Queries;

use App\Admin\Data\InterruptionMetricsData;
use App\Domain\Notifications\Services\NotificationPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Can we interrupt at the right time?" from the ledger (E44; PRD §7.2, §7.3, §12).
 *
 * Everything here is read from the `notifications` table, which was built (E30) to record
 * DENIALS as well as sends precisely so this question would be answerable — a table that
 * kept only what it sent could never tell you about the restraint that is the whole product.
 *
 * ## What this can and cannot yet say
 *
 * It measures what the SERVER sees: what the policy decided, what was delivered, what the
 * user did with it, and whether they abandoned Trip Mode. It does NOT yet measure the parts
 * that need client instrumentation — battery-complaint rate, permission-grant rate per power
 * tier, the "why did I get this" open rate — because those signals are collected on the
 * handset (E34), which does not exist yet. Those fields are honestly absent rather than
 * faked to zero. When the mobile client lands, they extend this query; they do not replace it.
 */
final class InterruptionMetrics
{
    public function __invoke(string $range = '7d'): InterruptionMetricsData
    {
        $since = $this->since($range);

        // One pass over the notification decisions in range.
        $n = DB::table('notifications')
            ->where('created_at', '>=', $since)
            ->selectRaw('
                COUNT(*) AS considered,
                COUNT(*) FILTER (WHERE allowed) AS allowed,
                COUNT(*) FILTER (WHERE sent_at IS NOT NULL) AS sent,
                COUNT(*) FILTER (WHERE opened_at IS NOT NULL) AS opened,
                COUNT(*) FILTER (WHERE dismissed_at IS NOT NULL) AS dismissed
            ')
            ->first();

        $considered = (int) $n->considered;
        $sent = (int) $n->sent;

        // Why we stayed quiet, by gate — the interesting half.
        $denials = DB::table('notifications')
            ->where('created_at', '>=', $since)
            ->whereNotNull('denied_by')
            ->groupBy('denied_by')
            ->selectRaw('denied_by, COUNT(*) AS n')
            ->pluck('n', 'denied_by')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        // Days on which the daily budget was the thing that stopped a push — the early
        // warning of an over-eager policy.
        $budgetSaturatedDays = DB::table('notifications')
            ->where('created_at', '>=', $since)
            ->where('denied_by', 'daily_budget')
            ->distinct()
            ->selectRaw('DATE(created_at) AS d, user_id')
            ->get()
            ->count();

        // Trip Mode: started, and abandoned (turned on then off) within the window.
        $tripModeStarted = DB::table('trips')
            ->where('trip_mode_started_at', '>=', $since)
            ->count();

        $tripModeAbandoned = DB::table('trips')
            ->where('trip_mode_started_at', '>=', $since)
            ->whereNotNull('trip_mode_ended_at')
            // Ended while the trip itself was still going — abandonment, not a trip that
            // simply finished. A trip that ended and took Trip Mode with it is not a signal.
            ->where(function ($q): void {
                $q->whereNull('ended_at')->orWhereColumn('trip_mode_ended_at', '<', 'ended_at');
            })
            ->count();

        return new InterruptionMetricsData(
            considered: $considered,
            allowed: (int) $n->allowed,
            silenceRate: $this->rate($considered - (int) $n->allowed, $considered),
            sent: $sent,
            opened: (int) $n->opened,
            dismissed: (int) $n->dismissed,
            acceptanceRate: $this->rate((int) $n->opened, $sent),
            annoyanceRate: $this->rate((int) $n->dismissed, $sent),
            denialsByGate: $denials,
            tripModeStarted: $tripModeStarted,
            tripModeAbandoned: $tripModeAbandoned,
            abandonmentRate: $this->rate($tripModeAbandoned, $tripModeStarted),
            budgetSaturatedDays: $budgetSaturatedDays,
            range: $range,
            policyVersion: NotificationPolicy::VERSION,
        );
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator === 0 ? 0.0 : round($numerator / $denominator, 3);
    }

    private function since(string $range): CarbonImmutable
    {
        $now = now()->toImmutable();

        return match ($range) {
            '24h' => $now->subDay(),
            '30d' => $now->subDays(30),
            'all' => CarbonImmutable::createFromTimestamp(0),
            default => $now->subDays(7),
        };
    }
}
