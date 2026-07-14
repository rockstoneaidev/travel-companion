<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

use App\Domain\Places\Models\ScoutRun;

/**
 * "What have the scouts been doing?" — Places' published answer (conventions/01).
 *
 * The emulator's pipeline log wants to show scouts firing and cache hits landing, and it
 * may not read `scout_runs` to find out: a module's tables are its own, and the arch
 * test says so out loud. So Places answers the question instead of exposing the row.
 *
 * Note what a run is NOT scoped to: a session, or a user. Scouting is work done on
 * SHARED tiles (PRD §9.3 — "scouting Beaune once serves every user in Beaune"), so
 * "recent activity" is genuinely global, and the cockpit says so rather than implying an
 * operator's pin caused every line it prints.
 */
final class ScoutActivity
{
    /** @return list<array{at: ?string, scout: string, version: string, tiles: int, hits: int, filled: int, candidates: int, duration_ms: int}> */
    public function recent(int $limit = 12): array
    {
        return ScoutRun::query()
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (ScoutRun $run): array => [
                'at' => $run->created_at?->toIso8601String(),
                'scout' => (string) $run->scout,
                'version' => (string) $run->scout_version,
                'tiles' => (int) $run->tiles_requested,
                'hits' => (int) $run->tiles_hit,
                'filled' => (int) $run->tiles_filled,
                'candidates' => (int) $run->candidates,
                'duration_ms' => (int) $run->duration_ms,
            ])
            ->all();
    }
}
