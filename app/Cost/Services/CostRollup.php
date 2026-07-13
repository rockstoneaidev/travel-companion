<?php

declare(strict_types=1);

namespace App\Cost\Services;

use App\Enums\CostActorKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns the ledger into the three numbers a human asks for (docs/COST.md §2.2, §7.1).
 *
 * The ledger is causal: whoever lit the fuse pays. That is the only thing knowable at
 * write time, and it is a terrible bill — the first traveller into a cold region pays
 * for a tile the next forty read for free, so a causal invoice would make
 * first-user-into-Dijon look like a whale.
 *
 * The fix is not to change what the ledger records. It is to derive, once a day, what
 * the ledger cannot know at write time because the denominator has not happened yet:
 *
 *  1. AMORTISED — a cached artifact's cost, split across everyone who consumed it that
 *     day. Concretely: every cache HIT row carries `would_have_billed`, so a hit is
 *     evidence of consumption. For each (vendor, resource) we take the day's real spend
 *     and divide it across all consumers — payers and free-riders alike — weighted by
 *     how much each consumed. The person who paid gets a rebate; the people who
 *     benefited pick it up.
 *
 *  2. CAPEX SHARE — `system` spend (region packs, world-model builds) is spent on
 *     nobody's behalf. Attribute it to the admin who clicked the button and one operator
 *     "costs" €400. It belongs to the REGION, spread over that region's active users.
 *
 *  3. SAVED — Σ(would_have_billed − billed): what shared caching actually bought us.
 *     conventions/12 calls this a product metric, and it is: it is the difference
 *     between a viable unit economic and an unviable one.
 *
 * Idempotent: upserts by (day, user, actor, category, vendor). Re-running a day is
 * expected — it is what you do when a price sheet is corrected.
 */
final class CostRollup
{
    /** @return int rows written */
    public function __invoke(Carbon $day): int
    {
        $start = $day->copy()->startOfDay();
        $end = $day->copy()->addDay()->startOfDay();

        $buckets = $this->baseBuckets($start, $end);

        if ($buckets === []) {
            return 0;
        }

        $this->applyAmortization($buckets, $start, $end);
        $this->applyCapexShare($buckets, $start, $end);
        $this->applyProductDenominators($buckets, $start, $end);

        DB::table('cost_daily')->upsert(
            array_values($buckets),
            ['day', 'user_id', 'actor_kind', 'category', 'vendor'],
            [
                'billed_usd_micros', 'amortized_usd_micros', 'capex_share_usd_micros',
                'saved_usd_micros', 'cpu_ms', 'calls', 'input_tokens', 'output_tokens',
                'active_trip_minutes', 'recommendations_served', 'updated_at',
            ],
        );

        return count($buckets);
    }

    /**
     * The honest sums, straight off the ledger. Everything else is layered on top.
     *
     * @return array<string, array<string, mixed>>
     */
    private function baseBuckets(Carbon $start, Carbon $end): array
    {
        $rows = DB::table('cost_events')
            ->whereBetween('occurred_at', [$start, $end])
            ->groupBy('user_id', 'actor_kind', 'category', 'vendor')
            ->selectRaw('user_id, actor_kind, category, vendor')
            ->selectRaw('SUM(billed_usd_micros) AS billed')
            ->selectRaw('SUM(would_have_billed_usd_micros - billed_usd_micros) AS saved')
            ->selectRaw('SUM(cpu_ms) AS cpu_ms, SUM(calls) AS calls')
            ->selectRaw('SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens')
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            $buckets[$this->key($row->user_id, $row->actor_kind, $row->category, $row->vendor)] = [
                'day' => $start->toDateString(),
                'user_id' => $row->user_id,
                'actor_kind' => $row->actor_kind,
                'category' => $row->category,
                'vendor' => $row->vendor,
                'billed_usd_micros' => (int) $row->billed,
                // Starts at the causal number and is then MOVED by amortisation. A bucket
                // nobody shared with stays exactly where it started, which is right.
                'amortized_usd_micros' => (int) $row->billed,
                'capex_share_usd_micros' => 0,
                'saved_usd_micros' => (int) $row->saved,
                'cpu_ms' => (int) $row->cpu_ms,
                'calls' => (int) $row->calls,
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'active_trip_minutes' => 0,
                'recommendations_served' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $buckets;
    }

    /**
     * Move shared costs from whoever paid to everyone who consumed.
     *
     * The unit of sharing is (vendor, resource) — a cached LLM line, a cached route.
     * `would_have_billed` is the honest measure of consumption for BOTH cases: it is
     * what the call cost the payer, and what it would have cost the free-rider. So it is
     * the weight, and the day's real spend for that resource is what gets divided.
     *
     * Only `user` rows take a share. System capex is handled separately (§2.1) and
     * emulated-admin traffic must never dilute a real user's number (ADMIN §2.4) — an
     * operator testing in Liljeholmen all afternoon would otherwise make every real
     * traveller look cheaper than they are.
     *
     * @param  array<string, array<string, mixed>>  $buckets
     */
    private function applyAmortization(array &$buckets, Carbon $start, Carbon $end): void
    {
        $rows = DB::table('cost_events')
            ->whereBetween('occurred_at', [$start, $end])
            ->where('actor_kind', CostActorKind::User->value)
            ->whereNotNull('user_id')
            ->where('would_have_billed_usd_micros', '>', 0)
            ->groupBy('vendor', 'resource', 'category', 'user_id')
            ->selectRaw('vendor, resource, category, user_id')
            ->selectRaw('SUM(would_have_billed_usd_micros) AS consumed')
            ->selectRaw('SUM(billed_usd_micros) AS paid')
            ->get();

        /** @var array<string, array{spend: int, consumed: int, users: array<int, array{consumed: int, category: string}>}> $resources */
        $resources = [];

        foreach ($rows as $row) {
            $id = $row->vendor.'|'.$row->resource;

            $resources[$id] ??= ['spend' => 0, 'consumed' => 0, 'users' => []];
            $resources[$id]['spend'] += (int) $row->paid;
            $resources[$id]['consumed'] += (int) $row->consumed;
            $resources[$id]['users'][(int) $row->user_id] = [
                'consumed' => (int) $row->consumed,
                'category' => (string) $row->category,
            ];
        }

        // Zero the user buckets before re-filling them: amortisation REPLACES the causal
        // number, it does not add to it. (A bucket for a resource nobody shared simply
        // gets its own spend handed straight back below, which is the correct no-op.)
        foreach ($buckets as $key => $bucket) {
            if ($bucket['actor_kind'] === CostActorKind::User->value && $bucket['user_id'] !== null) {
                $buckets[$key]['amortized_usd_micros'] = 0;
            }
        }

        foreach ($resources as $id => $resource) {
            if ($resource['consumed'] <= 0 || $resource['spend'] <= 0) {
                continue;
            }

            [$vendor] = explode('|', $id);

            foreach ($resource['users'] as $userId => $consumption) {
                $share = intdiv($resource['spend'] * $consumption['consumed'], $resource['consumed']);

                $key = $this->key($userId, CostActorKind::User->value, $consumption['category'], $vendor);

                if (isset($buckets[$key])) {
                    $buckets[$key]['amortized_usd_micros'] += $share;
                }
            }
        }
    }

    /**
     * Region capex, spread over the region's active users that day.
     *
     * "Active in the region" is approximated by the users who spent anything in it —
     * which is what a cost row's `region_key` means. When a pack is drafted for a region
     * nobody visited that day, the capex stays on the system bucket rather than being
     * silently discarded: unallocated capex is still real money, and hiding it would make
     * the per-user numbers look better than they are.
     *
     * @param  array<string, array<string, mixed>>  $buckets
     */
    private function applyCapexShare(array &$buckets, Carbon $start, Carbon $end): void
    {
        $capexByRegion = DB::table('cost_events')
            ->whereBetween('occurred_at', [$start, $end])
            ->where('actor_kind', CostActorKind::System->value)
            ->whereNotNull('region_key')
            ->groupBy('region_key')
            ->selectRaw('region_key, SUM(billed_usd_micros) AS spend')
            ->pluck('spend', 'region_key');

        foreach ($capexByRegion as $region => $spend) {
            if ((int) $spend <= 0) {
                continue;
            }

            $users = DB::table('cost_events')
                ->whereBetween('occurred_at', [$start, $end])
                ->where('actor_kind', CostActorKind::User->value)
                ->where('region_key', $region)
                ->whereNotNull('user_id')
                ->distinct()
                ->pluck('user_id');

            if ($users->isEmpty()) {
                continue;   // nobody to spread it over; it stays visible on the system bucket
            }

            $share = intdiv((int) $spend, $users->count());

            foreach ($users as $userId) {
                // Land it on the user's LLM bucket for the vendor that spent it — packs are
                // Gemini, and keeping the vendor honest keeps the breakdown honest.
                $key = $this->key((int) $userId, CostActorKind::User->value, 'llm', 'gemini');

                if (! isset($buckets[$key])) {
                    $buckets[$key] = $this->emptyBucket($start, (int) $userId, CostActorKind::User->value, 'llm', 'gemini');
                }

                $buckets[$key]['capex_share_usd_micros'] += $share;
            }
        }
    }

    /**
     * The denominators for ADMIN §7.1's first view: cost per active trip-hour.
     *
     * Emulated sessions are excluded upstream by actor kind — a founder testing all
     * afternoon must not appear as thirty cheap trip-hours (ADMIN §2.4).
     *
     * @param  array<string, array<string, mixed>>  $buckets
     */
    private function applyProductDenominators(array &$buckets, Carbon $start, Carbon $end): void
    {
        $sessions = DB::table('explore_sessions')
            ->whereBetween('started_at', [$start, $end])
            ->groupBy('user_id')
            ->selectRaw('user_id')
            ->selectRaw('SUM(EXTRACT(EPOCH FROM (COALESCE(ended_at, expires_at) - started_at)) / 60)::int AS minutes')
            ->pluck('minutes', 'user_id');

        $served = DB::table('recommendations')
            ->whereBetween('served_at', [$start, $end])
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) AS n')
            ->pluck('n', 'user_id');

        foreach ($buckets as $key => $bucket) {
            if ($bucket['user_id'] === null) {
                continue;
            }

            // Only on ONE bucket per user, or a user with three vendors would count their
            // trip minutes three times and cost-per-trip-hour would be a third of the truth.
            if ($bucket['category'] !== 'compute') {
                continue;
            }

            $buckets[$key]['active_trip_minutes'] = (int) ($sessions[$bucket['user_id']] ?? 0);
            $buckets[$key]['recommendations_served'] = (int) ($served[$bucket['user_id']] ?? 0);
        }
    }

    /** @return array<string, mixed> */
    private function emptyBucket(Carbon $start, ?int $userId, string $actor, string $category, string $vendor): array
    {
        return [
            'day' => $start->toDateString(),
            'user_id' => $userId,
            'actor_kind' => $actor,
            'category' => $category,
            'vendor' => $vendor,
            'billed_usd_micros' => 0,
            'amortized_usd_micros' => 0,
            'capex_share_usd_micros' => 0,
            'saved_usd_micros' => 0,
            'cpu_ms' => 0,
            'calls' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'active_trip_minutes' => 0,
            'recommendations_served' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function key(?int $userId, string $actor, string $category, string $vendor): string
    {
        return ($userId ?? 'null').'|'.$actor.'|'.$category.'|'.$vendor;
    }
}
