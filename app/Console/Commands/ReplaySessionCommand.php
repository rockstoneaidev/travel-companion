<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Feedback\Services\FeedbackLedger;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * The trip replayer (PRD §15.2): run a recorded session through the CURRENT
 * pipeline and diff against what was originally served. Read-only — replay
 * never writes recommendations.
 *
 * A session is now a SEQUENCE of serves, not one (E46): the feed re-anchors when the
 * user walks somewhere new, tops itself up when they dismiss, and re-serves when they
 * ask. So the replayer replays each rank pass in turn, under that pass's own clock and
 * its own anchor.
 *
 * The single-serve version of this file did not merely miss the later batches — it was
 * actively misleading about them. It took `served_at` from the row at position 1 and
 * used that one instant to replay everything, and it ranked from `explore_sessions
 * .origin`, which is where the session STARTED. Replaying a Hornstull batch from
 * Liljeholmen, an hour early, and calling the inevitable divergence a pipeline change
 * is exactly the "tool that lies" failure the truncated-clock comment in RankSession
 * exists to prevent. A replayer nobody can trust is worse than no replayer.
 */
final class ReplaySessionCommand extends Command
{
    protected $signature = 'replay:session {session : Explore session id}';

    protected $description = 'Replay every serve of a recorded session through the current pipeline and diff against the originals';

    public function handle(RankSession $rank): int
    {
        $session = ExploreSession::query()->findOrFail($this->argument('session'));
        $data = ExploreSessionData::fromModel($session);

        $original = Recommendation::query()
            ->where('explore_session_id', $session->id)
            ->orderBy('serve_group')
            ->orderBy('position')
            ->get();

        if ($original->isEmpty()) {
            $this->components->warn('Nothing was served for this session — nothing to diff against.');

            return self::FAILURE;
        }

        /*
         * One rank pass = one `served_at`, and that is the unit we replay.
         *
         * Not the serve GROUP: a group can hold more than one pass, because a dismiss
         * backfill appends to the batch on screen and does so at its own clock. Grouping
         * by the group would replay two passes as one and blame the difference on the
         * pipeline.
         */
        $passes = $original
            ->filter(fn (Recommendation $r): bool => $r->served_at !== null)
            ->groupBy(fn (Recommendation $r): string => $r->served_at->toIso8601String())
            ->sortKeys();

        if ($passes->isEmpty()) {
            $this->components->warn('No serve clock on these rows — nothing replayable.');

            return self::FAILURE;
        }

        $verdicts = [];

        foreach ($passes as $servedAt => $rows) {
            /** @var Recommendation $first */
            $first = $rows->first();

            $this->newLine();
            $this->line(sprintf(
                '  <fg=cyan;options=bold>serve %d</> · <fg=gray>%s · %s</>',
                $first->serve_group,
                $first->serve_reason->value,
                $servedAt,
            ));

            $verdicts[] = $this->replayPass(
                $rank,
                $data,
                $rows,
                $first,
                $this->exclusionsAt($original, $first),
            );
        }

        $this->newLine();
        $this->components->twoColumnDetail(
            'Session verdict',
            in_array(false, $verdicts, true)
                ? sprintf('diverged in %d of %d serves', count(array_filter($verdicts, static fn (bool $v): bool => ! $v)), count($verdicts))
                : sprintf('identical across all %d serves', count($verdicts)),
        );

        return self::SUCCESS;
    }

    /**
     * The places a given pass was FORBIDDEN to offer, as of the moment it ran.
     *
     * Reconstructed rather than assumed, because getting this wrong makes the replayer
     * lie in the most expensive direction: replay a pass without its exclusions and the
     * pipeline happily re-picks a café the user had already dismissed, the diff reports
     * a place the live serve "should" have shown, and someone goes hunting for a
     * ranking regression that is really just a missing filter.
     *
     * Two sources, matching RankSession::serve():
     *   - every place dismissed BEFORE this pass's clock (dismissals are session-wide
     *     and permanent — latest-wins over {dismissed, undismissed});
     *   - for a backfill, the places already sitting in the batch it topped up.
     *
     * @param  Collection<int, Recommendation>  $all
     * @return list<string>
     */
    private function exclusionsAt(Collection $all, Recommendation $pass): array
    {
        $at = $pass->served_at;

        $events = app(FeedbackLedger::class)->eventsForRecommendations(
            $all->pluck('id')->all(),
        );

        $excluded = [];

        foreach ($all as $row) {
            $placeId = $row->score_inputs['candidate']['place_id'] ?? null;

            if (! is_string($placeId)) {
                continue;
            }

            // Already on screen in the batch this pass was topping up.
            if (! $pass->serve_reason->opensNewGroup()
                && $row->serve_group === $pass->serve_group
                && $row->position < $pass->position) {
                $excluded[] = $placeId;

                continue;
            }

            $dismissed = null;

            foreach ($events[$row->id] ?? [] as $event) {
                if (CarbonImmutable::parse($event['occurred_at'])->greaterThanOrEqualTo($at)) {
                    break;   // ordered by occurred_at — everything from here on is the future
                }

                if (FeedbackEvent::tryFrom($event['event'])?->togglesDismiss() === true) {
                    $dismissed = $event['event'];
                }
            }

            if ($dismissed === FeedbackEvent::Dismissed->value) {
                $excluded[] = $placeId;
            }
        }

        return array_values(array_unique($excluded));
    }

    /**
     * Replay ONE rank pass, from where it was actually ranked and when.
     *
     * @param  Collection<int, Recommendation>  $rows
     * @param  list<string>  $exclude
     * @return bool whether the replay reproduced the original serve exactly
     */
    private function replayPass(RankSession $rank, ExploreSessionData $data, Collection $rows, Recommendation $first, array $exclude): bool
    {
        // Replay under the original serve clock, so temporal urgency and budget
        // depletion recompute exactly (SCORING §2.2 recomputability)...
        $at = $first->served_at->toImmutable();

        // ...and from the original ANCHOR, which for any serve after the first is not
        // where the session began. A null anchor means the trip's location history was
        // erased (PRD §16) — the trace survives, the geography does not, so the honest
        // fallback is the session origin, which erasure has nulled too.
        $anchored = $first->anchor !== null ? $data->reAnchoredAt($first->anchor) : $data;

        if ($anchored->origin === null) {
            $this->components->warn('  Location history erased for this trip — cannot replay this serve.');

            return true;   // not a divergence; there is simply nothing to compare
        }

        // Same clock, same anchor, same exclusions, same batch size as the live pass —
        // every input the pipeline actually ran on, so a difference in the OUTPUT can
        // only be the pipeline itself. That is the entire contract of a replayer.
        $plan = $rank->plan($anchored, $at, null, $exclude, $rows->count());

        $originalByPlace = $rows->mapWithKeys(fn (Recommendation $r): array => [
            $r->score_inputs['candidate']['place_id'] => ['name' => $r->score_inputs['candidate']['name'], 'composite' => $r->scores['composite'], 'position' => $r->position, 'version' => $r->scoring_model_version],
        ]);
        $replayByPlace = collect($plan['picked'])->mapWithKeys(fn (array $c, int $i): array => [
            $c['place_id'] => ['name' => $c['name'], 'composite' => $c['composite'], 'position' => $i + 1],
        ]);

        $tableRows = [];
        foreach ($originalByPlace as $placeId => $orig) {
            $replayed = $replayByPlace->get($placeId);
            $tableRows[] = [
                $orig['name'],
                "#{$orig['position']} @ {$orig['composite']} ({$orig['version']})",
                $replayed === null ? 'DROPPED' : "#{$replayed['position']} @ {$replayed['composite']} ({$plan['model']->version})",
                $replayed === null ? '✗' : ($replayed['composite'] === $orig['composite'] ? '=' : 'Δ '.round($replayed['composite'] - $orig['composite'], 4)),
            ];
        }
        foreach ($replayByPlace as $placeId => $replayed) {
            if (! $originalByPlace->has($placeId)) {
                $tableRows[] = [$replayed['name'], '—', "#{$replayed['position']} @ {$replayed['composite']} (NEW)", '+'];
            }
        }

        $this->stageFunnel($plan);

        $this->table(['Place', 'Original', 'Replay', 'Diff'], $tableRows);

        $identical = $replayByPlace->keys()->all() === $originalByPlace->keys()->all()
            && $replayByPlace->every(fn (array $r, string $id): bool => $r['composite'] === $originalByPlace[$id]['composite']);

        $this->components->twoColumnDetail('Verdict', $identical ? 'identical serve' : 'diverged (data, constants, or profile changed since)');

        return $identical;
    }

    /**
     * The stages *before* the serve. Diffing only what was served answers "what
     * changed" but never "why was this dropped" — and a candidate that dies at
     * the reachability gate or an evidence gate leaves no trace on the served
     * list at all.
     *
     * @param  array<string, mixed>  $plan
     */
    private function stageFunnel(array $plan): void
    {
        $scouted = array_sum(array_column($plan['scout_summary'], 'candidates'));
        $held = $plan['held'];
        $unreachable = $plan['unreachable'];

        $this->components->twoColumnDetail('<fg=gray>scouted</>', (string) $scouted);
        $this->components->twoColumnDetail('<fg=gray>dropped: unreachable</>', (string) $unreachable['count']);
        $this->components->twoColumnDetail('<fg=gray>held at Decide</>', (string) count($held));
        $this->components->twoColumnDetail('<fg=gray>served</>', (string) count($plan['picked']));

        if ($unreachable['sample'] !== []) {
            $this->newLine();
            $this->line('  <fg=yellow>Out of reach on this budget (PRD §10 step 8):</>');

            foreach (array_slice($unreachable['sample'], 0, 5) as $candidate) {
                $r = $candidate['reachability'];
                $this->line(sprintf(
                    '    %-32s %s min travel + %s dwell vs %s left',
                    mb_strimwidth((string) $candidate['name'], 0, 32, '…'),
                    $r['travel_min'] ?? '?', $r['dwell_min'] ?? '?', $r['remaining_min'] ?? '?',
                ));
            }
        }

        if ($held === []) {
            $this->newLine();

            return;
        }

        $this->newLine();
        $this->line('  <fg=yellow>Held at the evidence gates (SCORING §2.1) — never eligible for the feed:</>');

        $byReason = [];
        foreach ($held as $candidate) {
            $byReason[$candidate['hold']['reason']][] = $candidate['name'];
        }

        foreach ($byReason as $reason => $names) {
            $this->line(sprintf('    %-24s %d  (%s)', $reason, count($names), implode(', ', array_slice($names, 0, 3))));
        }

        $this->newLine();
    }
}
