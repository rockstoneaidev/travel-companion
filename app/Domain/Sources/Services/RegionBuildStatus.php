<?php

declare(strict_types=1);

namespace App\Domain\Sources\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Is this region being built right now, and how far has it got? (ADMIN.md)
 *
 * The admin console had a button and nothing else: no progress, no state, and no
 * guard — so pressing it twice queued two builds, pressing it five times queued
 * five, and the only way to know whether anything was happening was to go and read
 * Horizon. A button that gives no feedback is a button people press again.
 *
 * The marker is a CACHE key with a TTL rather than a database row, on purpose: a
 * build that dies in a way we never hear about must not wedge the button forever.
 *
 * A TTL ALONE WAS NOT ENOUGH, and staging proved it: the console said
 * "building · evidence" for hours with the button greyed out, for a build that was
 * already dead. `finish()` — the only thing that releases the claim — is called on
 * SUCCESS. A worker killed at its timeout never runs `failed()` either, and the last
 * phase's failure handler had nowhere to chain onward to, so the claim simply sat
 * there until the six-hour TTL expired. Six hours of a disabled button is not "safe",
 * it is broken.
 *
 * So a claim has to PROVE IT IS ALIVE. Every step of the build touches the key — each
 * phase, and each grid box as it lands — and a claim that has not been touched in
 * STALE_MINUTES is treated as dead: the console says so, and the button comes back.
 * Re-pressing is safe by construction, because every phase is idempotent.
 */
final class RegionBuildStatus
{
    /** Long enough for a big region (Stockholm is ~45 boxes at ~a minute each). */
    private const TTL_HOURS = 6;

    /**
     * How long a build may go without a sign of life before we stop believing in it.
     *
     * The longest legitimate silence is one grid box: a single Overpass query with its
     * own budget (300 s) plus HTTP timeout (150 s). Fifteen minutes is comfortably past
     * that and still short enough that nobody sits looking at a dead button.
     */
    private const STALE_MINUTES = 15;

    /** @return array{phase: string, started_at: string, stalled: bool}|null */
    public function current(string $regionKey): ?array
    {
        $claim = Cache::get($this->key($regionKey));

        if ($claim === null) {
            return null;
        }

        $beat = $claim['beat_at'] ?? $claim['started_at'] ?? null;

        return [
            'phase' => $claim['phase'],
            'started_at' => $claim['started_at'],

            // A build that has shown no sign of life is not a build. Saying so is the
            // difference between "wait" and "this is dead, press it again".
            'stalled' => $beat !== null && CarbonImmutable::parse($beat)->isBefore(now()->subMinutes(self::STALE_MINUTES)),
        ];
    }

    public function isBuilding(string $regionKey): bool
    {
        $current = $this->current($regionKey);

        // A stalled claim is not a build in progress — it is a corpse holding the door.
        return $current !== null && $current['stalled'] === false;
    }

    /** Claim the region. Returns false if a build is already under way. */
    public function start(string $regionKey): bool
    {
        if ($this->isBuilding($regionKey)) {
            return false;
        }

        Cache::put(
            $this->key($regionKey),
            ['phase' => 'queued', 'started_at' => now()->toIso8601String(), 'beat_at' => now()->toIso8601String()],
            now()->addHours(self::TTL_HOURS),
        );

        return true;
    }

    public function phase(string $regionKey, string $phase): void
    {
        $current = Cache::get($this->key($regionKey));

        Cache::put(
            $this->key($regionKey),
            [
                'phase' => $phase,
                'started_at' => $current['started_at'] ?? now()->toIso8601String(),
                'beat_at' => now()->toIso8601String(),
            ],
            now()->addHours(self::TTL_HOURS),
        );
    }

    /**
     * A sign of life, from deep inside a long phase.
     *
     * The OSM ingest is 45 boxes on ONE worker: forty-five minutes in which the phase
     * never changes. Without a heartbeat from the boxes themselves, that healthy build
     * would look exactly like a dead one, and we would offer to restart it half way
     * through.
     */
    public function heartbeat(string $regionKey): void
    {
        $current = Cache::get($this->key($regionKey));

        if ($current === null) {
            return;   // nobody is claiming this region; a stray box is not a build
        }

        Cache::put(
            $this->key($regionKey),
            [...$current, 'beat_at' => now()->toIso8601String()],
            now()->addHours(self::TTL_HOURS),
        );
    }

    public function finish(string $regionKey): void
    {
        Cache::forget($this->key($regionKey));
    }

    /**
     * Box progress for the source currently being ingested, straight from the batch.
     *
     * @return array{done: int, total: int, failed: int}|null
     */
    public function boxes(string $regionKey): ?array
    {
        $batch = DB::table('job_batches')
            ->where('name', 'like', "ingest {$regionKey}/%")
            ->orderByDesc('created_at')
            ->first();

        if ($batch === null || $batch->finished_at !== null) {
            return null;
        }

        return [
            'done' => (int) $batch->total_jobs - (int) $batch->pending_jobs,
            'total' => (int) $batch->total_jobs,
            'failed' => (int) $batch->failed_jobs,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Drafting — the same problem, and it deserved the same answer
    |--------------------------------------------------------------------------
    |
    | The build button got progress and the draft button did not, so pressing it
    | looked exactly like pressing nothing. A button that gives no feedback is a
    | button people press again — that is the entire lesson of the build button, and
    | I shipped the draft button without applying it.
    |
    | Drafting is worse to double-fire than building, too: each press is N calls to a
    | paid LLM.
    */

    /** @return array{target: int, started_at: string}|null */
    public function currentDraft(string $regionKey): ?array
    {
        return Cache::get($this->draftKey($regionKey));
    }

    public function isDrafting(string $regionKey): bool
    {
        return $this->currentDraft($regionKey) !== null;
    }

    /** Claim the region for drafting. False if a draft is already running. */
    public function startDraft(string $regionKey, int $target): bool
    {
        if ($this->isDrafting($regionKey)) {
            return false;
        }

        Cache::put(
            $this->draftKey($regionKey),
            ['target' => $target, 'started_at' => now()->toIso8601String()],
            now()->addHours(self::TTL_HOURS),
        );

        return true;
    }

    public function finishDraft(string $regionKey): void
    {
        Cache::forget($this->draftKey($regionKey));
    }

    private function key(string $regionKey): string
    {
        return "world-model:build:{$regionKey}";
    }

    private function draftKey(string $regionKey): string
    {
        return "world-model:draft:{$regionKey}";
    }
}
