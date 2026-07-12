<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use Illuminate\Console\Command;

/**
 * The trip replayer (PRD §15.2): run a recorded session through the CURRENT
 * pipeline and diff against what was originally served. Read-only — replay
 * never writes recommendations.
 */
final class ReplaySessionCommand extends Command
{
    protected $signature = 'replay:session {session : Explore session id}';

    protected $description = 'Replay a recorded session through the current pipeline and diff against the original serve';

    public function handle(RankSession $rank): int
    {
        $session = ExploreSession::query()->findOrFail($this->argument('session'));
        $data = ExploreSessionData::fromModel($session);

        $original = Recommendation::query()
            ->where('explore_session_id', $session->id)
            ->orderBy('position')
            ->get();

        if ($original->isEmpty()) {
            $this->components->warn('Nothing was served for this session — nothing to diff against.');

            return self::FAILURE;
        }

        // Replay under the original serve clock, so temporal urgency and
        // budget depletion recompute exactly (SCORING §2.2 recomputability).
        $at = $original->first()->served_at->toImmutable();
        $plan = $rank->plan($data, $at);

        $originalByPlace = $original->mapWithKeys(fn (Recommendation $r): array => [
            $r->score_inputs['candidate']['place_id'] => ['name' => $r->score_inputs['candidate']['name'], 'composite' => $r->scores['composite'], 'position' => $r->position, 'version' => $r->scoring_model_version],
        ]);
        $replayByPlace = collect($plan['picked'])->mapWithKeys(fn (array $c, int $i): array => [
            $c['place_id'] => ['name' => $c['name'], 'composite' => $c['composite'], 'position' => $i + 1],
        ]);

        $rows = [];
        foreach ($originalByPlace as $placeId => $orig) {
            $replayed = $replayByPlace->get($placeId);
            $rows[] = [
                $orig['name'],
                "#{$orig['position']} @ {$orig['composite']} ({$orig['version']})",
                $replayed === null ? 'DROPPED' : "#{$replayed['position']} @ {$replayed['composite']} ({$plan['model']->version})",
                $replayed === null ? '✗' : ($replayed['composite'] === $orig['composite'] ? '=' : 'Δ '.round($replayed['composite'] - $orig['composite'], 4)),
            ];
        }
        foreach ($replayByPlace as $placeId => $replayed) {
            if (! $originalByPlace->has($placeId)) {
                $rows[] = [$replayed['name'], '—', "#{$replayed['position']} @ {$replayed['composite']} (NEW)", '+'];
            }
        }

        $this->stageFunnel($plan);

        $this->table(['Place', 'Original', 'Replay', 'Diff'], $rows);
        $identical = $replayByPlace->keys()->all() === $originalByPlace->keys()->all()
            && $replayByPlace->every(fn (array $r, string $id): bool => $r['composite'] === $originalByPlace[$id]['composite']);
        $this->components->twoColumnDetail('Verdict', $identical ? 'identical serve' : 'diverged (data, constants, or profile changed since)');

        return self::SUCCESS;
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
