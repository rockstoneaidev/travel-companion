<?php

declare(strict_types=1);

namespace App\Cost\Queries;

use Illuminate\Support\Facades\DB;

/**
 * What a session actually cost — per served item, and in total (COST.md §2.2).
 *
 * Built for the emulator (E47): an operator driving a walk wants to know what that walk
 * would cost if a real traveller took it. The money is already in the ledger, correlated
 * to the exact `recommendation_id` it was spent on — it has simply never been shown next
 * to the thing it bought.
 *
 * TWO numbers, because one would be a lie either way:
 *
 *  - **billed** — what we actually paid. Zero on a cache hit, which is most of a warm
 *    tile's traffic, so on its own it flatters the second walk through the same
 *    neighbourhood into looking free.
 *  - **uncached** (`billed + would_have_billed`) — what it would have cost with a cold
 *    cache: the counterfactual §2.2 records precisely so this question is answerable.
 *    This is the honest "what does one of these actually cost to serve" figure, and the
 *    gap between the two is the "is shared caching working?" number (conventions/12).
 */
final class SessionSpend
{
    /**
     * @return array{
     *     total_billed_micros: int,
     *     total_uncached_micros: int,
     *     by_recommendation: array<string, array{billed_micros: int, uncached_micros: int}>
     * }
     */
    public function forSession(string $sessionId): array
    {
        $rows = DB::table('cost_events')
            ->where('session_id', $sessionId)
            ->selectRaw('recommendation_id')
            ->selectRaw('SUM(billed_usd_micros) AS billed')
            ->selectRaw('SUM(billed_usd_micros + would_have_billed_usd_micros) AS uncached')
            ->groupBy('recommendation_id')
            ->get();

        $byRecommendation = [];
        $totalBilled = 0;
        $totalUncached = 0;

        foreach ($rows as $row) {
            $billed = (int) $row->billed;
            $uncached = (int) $row->uncached;

            $totalBilled += $billed;
            $totalUncached += $uncached;

            /*
             * Session-level spend — the scouting, the weather, the rank itself — has no
             * recommendation to hang on. It is real money and it belongs in the TOTAL, but
             * attributing it to an arbitrary card would be inventing a number. So it is
             * counted once, at the top, and not apportioned.
             */
            if ($row->recommendation_id !== null) {
                $byRecommendation[(string) $row->recommendation_id] = [
                    'billed_micros' => $billed,
                    'uncached_micros' => $uncached,
                ];
            }
        }

        return [
            'total_billed_micros' => $totalBilled,
            'total_uncached_micros' => $totalUncached,
            'by_recommendation' => $byRecommendation,
        ];
    }
}
