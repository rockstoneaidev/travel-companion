<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Queries;

use App\Domain\Places\Services\ScoutActivity;
use App\Domain\Recommendations\Models\Recommendation;

/**
 * The machine, thinking out loud (E47; ADMIN §6, §8).
 *
 * Every number here was already being written down and none of it was ever readable:
 * `scout_runs` records which scouts ran and whether they hit cache; the serve trace
 * records, per PRD §15.1, exactly what the pipeline threw away and why — the
 * reachability gate's casualties, the evidence gates' holds, the near-misses that lost
 * to a better item. It is the most honest thing in the codebase and it lives in a jsonb
 * column nobody looks at.
 *
 * This query is that column, read aloud. It invents nothing and computes nothing: if a
 * line here surprises you, the pipeline surprised you, which is the entire point of
 * having a cockpit.
 */
final class SessionPipelineLog
{
    public function __construct(
        // Through Places' published service, never its `scout_runs` table: a module's
        // tables are its own (conventions/01, enforced by tests/Arch).
        private readonly ScoutActivity $scouts,
    ) {}

    /** @return list<array{at: ?string, stage: string, line: string}> */
    public function forSession(string $sessionId, int $limit = 40): array
    {
        $lines = [];

        $serves = Recommendation::query()
            ->where('explore_session_id', $sessionId)
            ->orderByDesc('served_at')
            ->orderBy('position')
            ->get();

        // One entry per SERVE (a rank pass), not per row: five recommendations are one
        // decision, and printing it five times would bury the decision in its own output.
        foreach ($serves->groupBy(fn (Recommendation $r): string => (string) $r->served_at?->toIso8601String()) as $at => $rows) {
            /** @var Recommendation $first */
            $first = $rows->first();
            $funnel = $first->score_inputs['funnel'] ?? [];
            $cost = $first->cost ?? [];

            $unreachable = (int) ($funnel['unreachable']['count'] ?? 0);
            $held = count($funnel['held'] ?? []);
            $nearMisses = count($funnel['near_misses'] ?? []);
            $excluded = (int) ($funnel['excluded'] ?? 0);
            $served = $rows->count();

            // "reachability dropped 14 of 60" — the denominator is everything that was in
            // the running, which is the only number that makes the numerator mean anything.
            $considered = $unreachable + $held + $nearMisses + $served;

            $lines[] = [
                'at' => $at ?: null,
                'stage' => 'serve',
                'line' => sprintf(
                    'serve %d (%s) from %s — served %d, held %d, near-missed %d%s',
                    $first->serve_group,
                    $first->serve_reason->value,
                    $first->anchor_h3_index ?? 'unknown cell',
                    $served,
                    $held,
                    $nearMisses,
                    $excluded > 0 ? sprintf(', %d excluded (already refused)', $excluded) : '',
                ),
            ];

            $lines[] = [
                'at' => $at ?: null,
                'stage' => 'gate',
                'line' => sprintf(
                    'reachability dropped %d of %d considered · ranked in %d ms · tiles %d hit / %d filled',
                    $unreachable,
                    $considered,
                    (int) ($cost['rank_ms'] ?? 0),
                    (int) ($cost['scout_tiles_hit'] ?? 0),
                    (int) ($cost['scout_tiles_filled'] ?? 0),
                ),
            ];
        }

        /*
         * Scout runs are NOT session-scoped — `scout_runs` has no session column (it is a
         * record of work done on shared tiles, and the tile cache is shared across users
         * by design, PRD §9.3). So these are the most recent runs, whoever caused them.
         *
         * Said out loud rather than quietly implied: an operator watching this log must
         * not read "NearbyPlaceScout, cache miss" as necessarily *their* pin's doing.
         */
        foreach ($this->scouts->recent() as $run) {
            $lines[] = [
                'at' => $run['at'],
                'stage' => 'scout',
                'line' => sprintf(
                    '%s v%s — %d tiles (%d hit, %d filled), %d candidates in %d ms',
                    $run['scout'],
                    $run['version'],
                    $run['tiles'],
                    $run['hits'],
                    $run['filled'],
                    $run['candidates'],
                    $run['duration_ms'],
                ),
            ];
        }

        usort($lines, static fn (array $a, array $b): int => ($b['at'] ?? '') <=> ($a['at'] ?? ''));

        return array_slice($lines, 0, $limit);
    }
}
