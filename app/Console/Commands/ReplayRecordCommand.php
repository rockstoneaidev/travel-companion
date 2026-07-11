<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Trips\Models\ExploreSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Gold-trace plumbing (PRD §15.2): export a real session — founder walks in
 * Stockholm — as a fixture the replay suite pins ranking changes against.
 */
final class ReplayRecordCommand extends Command
{
    protected $signature = 'replay:record {session : Explore session id} {--dir=tests/Fixtures/GoldTraces}';

    protected $description = 'Export a session and its serve as a gold-trace fixture';

    public function handle(): int
    {
        $session = ExploreSession::query()->with('trip')->findOrFail($this->argument('session'));

        $served = Recommendation::query()
            ->where('explore_session_id', $session->id)
            ->orderBy('position')
            ->get()
            ->map(fn (Recommendation $r): array => [
                'position' => $r->position,
                'place_id' => $r->score_inputs['candidate']['place_id'],
                'name' => $r->score_inputs['candidate']['name'],
                'composite' => $r->scores['composite'],
                'scoring_model_version' => $r->scoring_model_version,
            ])
            ->all();

        $trace = [
            'recorded_at' => now()->toIso8601String(),
            'session' => [
                'id' => $session->id,
                'user_id' => $session->user_id,
                'trip_id' => $session->trip_id,
                'origin' => $session->origin?->toArray(),
                'time_budget_minutes' => $session->time_budget_minutes,
                'travel_mode' => $session->travel_mode->value,
                'heading' => $session->heading,
                'destination_point' => $session->destination_point?->toArray(),
                'started_at' => $session->started_at->toIso8601String(),
            ],
            'served' => $served,
            'served_at' => $served === [] ? null : Recommendation::query()
                ->where('explore_session_id', $session->id)->min('served_at'),
        ];

        $dir = (string) $this->option('dir');
        File::ensureDirectoryExists(base_path($dir));
        $path = base_path("{$dir}/{$session->id}.json");
        File::put($path, json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->components->info("Gold trace written: {$path} (".count($served).' served items)');

        return self::SUCCESS;
    }
}
