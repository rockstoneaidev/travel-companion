<?php

declare(strict_types=1);

namespace App\Cost\Queries;

use App\Cost\Services\PriceBook;
use App\Cost\Services\SpendGuard;
use App\Enums\CostActorKind;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * /admin/costs — the drill-down (docs/COST.md §7.3, ADMIN §7.1).
 *
 * The navigation rule this exists to serve: EVERY NUMBER IS A LINK ONE LEVEL DOWN.
 * A cost dashboard whose numbers are dead ends tells you that you spent $4 and leaves
 * you to grep for why, which is the state we were already in without a dashboard.
 *
 * The top-N tables report SHARE OF TOTAL as well as absolute, because absolute alone
 * is unreadable: a $2 line item is the headline of a $10 day and noise in a $1,000 one,
 * and the operator should not have to do that division in their head at 7am.
 */
final class CostExplorer
{
    public function __construct(
        private readonly SpendGuard $guard,
        private readonly PriceBook $prices,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $range = '7d', array $filters = []): array
    {
        [$since, $until] = $this->window($range);

        return [
            'range' => $range,
            'filters' => (object) $filters,
            'totals' => $this->totals($since, $until, $filters),
            'byCategory' => $this->breakdown($since, $until, $filters, 'category'),
            'byVendor' => $this->breakdown($since, $until, $filters, 'vendor'),
            'byResource' => $this->breakdown($since, $until, $filters, 'resource'),
            'byActor' => $this->breakdown($since, $until, $filters, 'actor_kind'),
            'byModel' => $this->breakdown($since, $until, $filters, 'model'),
            'byPromptVersion' => $this->breakdown($since, $until, $filters, 'prompt_version'),
            'byRegion' => $this->breakdown($since, $until, $filters, 'region_key'),
            'byUser' => $this->topUsers($since, $until, $filters),
            'daily' => $this->daily($since, $until, $filters),
            'productMetrics' => $this->productMetrics($since, $until),
            'events' => $this->events($since, $until, $filters),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function window(string $range): array
    {
        $tz = (string) config('cost.timezone');
        $now = now()->timezone($tz);

        $since = match ($range) {
            'today' => $now->copy()->startOfDay(),
            '30d' => $now->copy()->startOfDay()->subDays(29),
            'all' => Carbon::createFromTimestamp(0),
            default => $now->copy()->startOfDay()->subDays(6),
        };

        return [$since->utc(), $now->copy()->endOfDay()->utc()];
    }

    /** @param array<string, mixed> $filters */
    private function base(Carbon $since, Carbon $until, array $filters): Builder
    {
        $query = DB::table('cost_events')->whereBetween('occurred_at', [$since, $until]);

        // The drill-down. Each filter is one level deeper, and they compose — which is
        // what makes "$4 on Gemini" → "…on flash-lite" → "…on opportunity_summary.v1"
        // → "…these 40 rows" a walk rather than four separate reports.
        foreach (['category', 'vendor', 'resource', 'actor_kind', 'model', 'prompt_version', 'region_key'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function totals(Carbon $since, Carbon $until, array $filters): array
    {
        $row = $this->base($since, $until, $filters)
            ->selectRaw('COALESCE(SUM(billed_usd_micros), 0) AS billed')
            ->selectRaw('COALESCE(SUM(would_have_billed_usd_micros), 0) AS notional')
            ->selectRaw('COALESCE(SUM(would_have_billed_usd_micros - billed_usd_micros), 0) AS saved')
            ->selectRaw('COALESCE(SUM(calls), 0) AS calls')
            ->selectRaw('COALESCE(SUM(input_tokens + output_tokens), 0) AS tokens')
            ->selectRaw('COUNT(*) AS events')
            ->first();

        $notional = (int) $row->notional;

        return [
            'billedMicros' => (int) $row->billed,
            'savedMicros' => (int) $row->saved,
            'calls' => (int) $row->calls,
            'tokens' => (int) $row->tokens,
            'events' => (int) $row->events,
            // The cache hit rate that conventions/12 calls a product metric — expressed in
            // MONEY, not in call counts, because a 90% hit rate on free Overpass calls and
            // a 90% hit rate on paid Routes calls are not the same fact.
            'cacheSavingPercent' => $notional > 0 ? round((int) $row->saved / $notional * 100, 1) : 0.0,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function breakdown(Carbon $since, Carbon $until, array $filters, string $column): array
    {
        $total = (int) $this->base($since, $until, $filters)->sum('billed_usd_micros');

        return $this->base($since, $until, $filters)
            ->whereNotNull($column)
            ->groupBy($column)
            ->selectRaw("{$column} AS label")
            ->selectRaw('SUM(billed_usd_micros) AS micros')
            ->selectRaw('SUM(would_have_billed_usd_micros - billed_usd_micros) AS saved')
            ->selectRaw('SUM(calls) AS calls')
            ->orderByDesc('micros')
            ->limit(20)
            ->get()
            ->map(fn ($r): array => [
                'label' => (string) $r->label,
                'micros' => (int) $r->micros,
                'savedMicros' => (int) $r->saved,
                'calls' => (int) $r->calls,
                // Share of total: a $2 item is a headline in a $10 day and noise in a $1,000 one.
                'share' => $total > 0 ? round((int) $r->micros / $total * 100, 1) : 0.0,
            ])
            ->all();
    }

    /**
     * The abuse-detection view (COST.md §7.3): real users only.
     *
     * System capex and emulated-admin traffic are excluded here and ONLY here — this is
     * the one table whose question is "is a client looping?", and a €12 pack build at the
     * top of it would answer that question wrong every single day.
     *
     * @param  array<string, mixed>  $filters
     */
    private function topUsers(Carbon $since, Carbon $until, array $filters): array
    {
        return $this->base($since, $until, $filters)
            ->where('actor_kind', CostActorKind::User->value)
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->selectRaw('user_id')
            ->selectRaw('SUM(billed_usd_micros) AS micros')
            ->selectRaw('SUM(calls) AS calls')
            ->orderByDesc('micros')
            ->limit(20)
            ->get()
            ->map(fn ($r): array => [
                'userId' => (int) $r->user_id,
                'micros' => (int) $r->micros,
                'calls' => (int) $r->calls,
                'capMicros' => $this->guard->perUserCapMicros(),
                'spentTodayMicros' => $this->guard->spentTodayByUserMicros((int) $r->user_id),
            ])
            ->all();
    }

    /** @param array<string, mixed> $filters */
    private function daily(Carbon $since, Carbon $until, array $filters): array
    {
        return $this->base($since, $until, $filters)
            ->groupByRaw('date_trunc(\'day\', occurred_at)')
            ->selectRaw('date_trunc(\'day\', occurred_at) AS day')
            ->selectRaw('SUM(billed_usd_micros) AS micros')
            ->orderBy('day')
            ->get()
            ->map(fn ($r): array => [
                'day' => Carbon::parse($r->day)->toDateString(),
                'micros' => (int) $r->micros,
            ])
            ->all();
    }

    /**
     * ADMIN §7.1's first view, and PRD §14.3's budget metric: cost per active trip-hour.
     *
     * Read from `cost_daily`, because the denominators are a rollup concern — and
     * emulated sessions are excluded there (ADMIN §2.4), which is the whole reason this
     * number is not just "spend ÷ hours".
     */
    private function productMetrics(Carbon $since, Carbon $until): array
    {
        $row = DB::table('cost_daily')
            ->whereBetween('day', [$since->toDateString(), $until->toDateString()])
            ->where('actor_kind', CostActorKind::User->value)
            ->selectRaw('COALESCE(SUM(billed_usd_micros), 0) AS billed')
            ->selectRaw('COALESCE(SUM(amortized_usd_micros + capex_share_usd_micros), 0) AS amortized')
            ->selectRaw('COALESCE(SUM(active_trip_minutes), 0) AS minutes')
            ->selectRaw('COALESCE(SUM(recommendations_served), 0) AS served')
            ->first();

        $minutes = (int) $row->minutes;
        $served = (int) $row->served;

        return [
            'billedMicros' => (int) $row->billed,
            'amortizedMicros' => (int) $row->amortized,
            'activeTripMinutes' => $minutes,
            'recommendationsServed' => $served,
            'perTripHourMicros' => $minutes > 0 ? (int) round((int) $row->billed / ($minutes / 60)) : null,
            // conventions/10: "a €0.40 recommendation is a bug." This is the number that
            // would say so.
            'perRecommendationMicros' => $served > 0 ? (int) round((int) $row->billed / $served) : null,
            'rolledUp' => DB::table('cost_daily')->exists(),
        ];
    }

    /**
     * The bottom of the drill-down: the actual rows.
     *
     * No `user_id` beyond the id itself, and no URI — there is nothing here that is not
     * already in the ledger, and the ledger was designed not to hold a coordinate.
     *
     * @param  array<string, mixed>  $filters
     */
    private function events(Carbon $since, Carbon $until, array $filters): array
    {
        return $this->base($since, $until, $filters)
            ->orderByDesc('occurred_at')
            ->limit(100)
            ->get([
                'occurred_at', 'actor_kind', 'category', 'vendor', 'resource', 'model',
                'prompt_version', 'user_id', 'region_key', 'input_tokens', 'output_tokens',
                'calls', 'billed_usd_micros', 'would_have_billed_usd_micros', 'cached', 'price_version',
            ])
            ->map(fn ($r): array => [
                'occurredAt' => Carbon::parse($r->occurred_at)->toIso8601String(),
                'actorKind' => $r->actor_kind,
                'category' => $r->category,
                'vendor' => $r->vendor,
                'resource' => $r->resource,
                'model' => $r->model,
                'promptVersion' => $r->prompt_version,
                'userId' => $r->user_id,
                'regionKey' => $r->region_key,
                'inputTokens' => (int) $r->input_tokens,
                'outputTokens' => (int) $r->output_tokens,
                'calls' => (int) $r->calls,
                'micros' => (int) $r->billed_usd_micros,
                'wouldHaveMicros' => (int) $r->would_have_billed_usd_micros,
                'cached' => (bool) $r->cached,
                'priceVersion' => $r->price_version,
            ])
            ->all();
    }

    /**
     * The cost cockpit's controls (COST.md §7.4) — the things that let an operator stop
     * watching the dashboard.
     */
    public function controls(): array
    {
        $monthStart = now()->timezone((string) config('cost.timezone'))->startOfMonth()->utc();

        // Google's free monthly allowance: invisible in every spend-based view, because
        // free-tier usage bills $0 while eating runway. It is the "when do I actually
        // start paying" number, and it is not in any other panel by construction.
        $essentialsUsed = (int) DB::table('cost_events')
            ->where('occurred_at', '>=', $monthStart)
            ->where('cached', false)
            ->whereIn('resource', ['routes_essentials', 'place_details_essentials'])
            ->sum('calls');

        return [
            'paused' => $this->guard->paused(),
            'spentTodayMicros' => $this->guard->spentTodayMicros(),
            'dailyCapMicros' => $this->guard->dailyCapMicros(),
            'perUserCapMicros' => $this->guard->perUserCapMicros(),
            'priceVersion' => $this->prices->version(),
            'priceDrift' => Cache::get('cost:price-drift'),
            'freeTier' => [
                'used' => $essentialsUsed,
                'allowance' => (int) config('pricing.free_tier.monthly_essentials_events'),
            ],
        ];
    }
}
