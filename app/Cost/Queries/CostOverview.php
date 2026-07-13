<?php

declare(strict_types=1);

namespace App\Cost\Queries;

use App\Cost\Data\CostOverviewData;
use App\Cost\Services\SpendGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The numbers on the /admin overview strip (docs/COST.md §7.2).
 *
 * Two decisions worth stating, because both look like bugs from the outside:
 *
 * 1. THIS SHOWS THE WHOLE BILL — user spend, emulated-admin spend, and system/ingest
 *    capex, all of it. The wallet does not care who spent the money. Only *product*
 *    metrics (cost per trip-hour, cost per recommendation) filter by actor, which is
 *    what ADMIN §2.4 actually asks for. A strip that quietly excluded pack drafting
 *    would report €0.40 on the morning after a €12 region build.
 *
 * 2. "TODAY" IS THE KILL-SWITCH'S TODAY. Both read config('cost.timezone'). A strip
 *    that says "$3 spent today" while the breaker thinks the day rolled over an hour
 *    ago is a strip nobody trusts twice — and trust is the entire function of this
 *    widget.
 *
 * At pilot volumes these are direct aggregates over the (partitioned) ledger. When
 * `cost_daily` lands with the rollup (E25), month and all-time move to reading it;
 * today stays live, because a cost strip that is a day stale is a cost strip that
 * cannot tell you what is happening right now.
 */
final class CostOverview
{
    public function __construct(private readonly SpendGuard $guard) {}

    public function __invoke(): CostOverviewData
    {
        $tz = (string) config('cost.timezone');

        $startOfDay = now()->timezone($tz)->startOfDay()->utc();
        $startOfMonth = now()->timezone($tz)->startOfMonth()->utc();

        $today = $this->sum($startOfDay);
        $month = $this->sum($startOfMonth);

        return new CostOverviewData(
            todayMicros: $today['billed'],
            monthMicros: $month['billed'],
            allTimeMicros: $this->sum(null)['billed'],
            dailyCapMicros: $this->guard->dailyCapMicros(),
            projectedMonthMicros: $this->projectMonth($month['billed']),
            savedTodayMicros: $today['saved'],
            // Read from the guard, not from the sums: the guard is what actually
            // gates the calls, so if the two ever disagree the UI must show what is
            // TRUE, not what is tidy.
            capReached: $this->guard->spentTodayMicros() >= $this->guard->dailyCapMicros(),
            paused: $this->guard->paused(),
            topLineItem: $this->topLineItem($startOfDay),
        );
    }

    /** @return array{billed: int, saved: int} */
    private function sum(?Carbon $since): array
    {
        $row = DB::table('cost_events')
            ->when($since !== null, fn ($q) => $q->where('occurred_at', '>=', $since))
            ->selectRaw('COALESCE(SUM(billed_usd_micros), 0) AS billed')
            ->selectRaw('COALESCE(SUM(would_have_billed_usd_micros - billed_usd_micros), 0) AS saved')
            ->first();

        return [
            'billed' => (int) ($row->billed ?? 0),
            'saved' => (int) ($row->saved ?? 0),
        ];
    }

    /**
     * Linear burn: what the month costs if the rest of it looks like the part we have
     * seen. Crude, and honest about being crude — the UI labels it "projected".
     *
     * The alternative (a trailing-average model) would be more sophisticated and no
     * more true: at pilot scale a single region build moves this number more than any
     * trend in it.
     */
    private function projectMonth(int $monthToDate): int
    {
        $now = now()->timezone((string) config('cost.timezone'));
        $elapsedDays = max(1, $now->day);

        return (int) round($monthToDate / $elapsedDays * $now->daysInMonth);
    }

    /** @return array{vendor: string, resource: string, micros: int}|null */
    private function topLineItem(Carbon $since): ?array
    {
        $row = DB::table('cost_events')
            ->where('occurred_at', '>=', $since)
            ->where('billed_usd_micros', '>', 0)
            ->groupBy('vendor', 'resource')
            ->selectRaw('vendor, resource, SUM(billed_usd_micros) AS micros')
            ->orderByDesc('micros')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'vendor' => (string) $row->vendor,
            'resource' => (string) $row->resource,
            'micros' => (int) $row->micros,
        ];
    }
}
