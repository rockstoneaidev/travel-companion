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

        $this->table(['Place', 'Original', 'Replay', 'Diff'], $rows);
        $identical = $replayByPlace->keys()->all() === $originalByPlace->keys()->all()
            && $replayByPlace->every(fn (array $r, string $id): bool => $r['composite'] === $originalByPlace[$id]['composite']);
        $this->components->twoColumnDetail('Verdict', $identical ? 'identical serve' : 'diverged (data, constants, or profile changed since)');

        return self::SUCCESS;
    }
}
