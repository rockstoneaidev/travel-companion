<?php

declare(strict_types=1);

namespace App\Domain\Sources\Services;

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
 * The worst case is that you can re-press it a few hours early, which is safe —
 * every phase is idempotent.
 */
final class RegionBuildStatus
{
    /** Long enough for a big region (Stockholm is ~45 boxes at ~a minute each). */
    private const TTL_HOURS = 6;

    /** @return array{phase: string, started_at: string}|null */
    public function current(string $regionKey): ?array
    {
        return Cache::get($this->key($regionKey));
    }

    public function isBuilding(string $regionKey): bool
    {
        return $this->current($regionKey) !== null;
    }

    /** Claim the region. Returns false if a build is already under way. */
    public function start(string $regionKey): bool
    {
        if ($this->isBuilding($regionKey)) {
            return false;
        }

        Cache::put(
            $this->key($regionKey),
            ['phase' => 'queued', 'started_at' => now()->toIso8601String()],
            now()->addHours(self::TTL_HOURS),
        );

        return true;
    }

    public function phase(string $regionKey, string $phase): void
    {
        $current = $this->current($regionKey);

        Cache::put(
            $this->key($regionKey),
            ['phase' => $phase, 'started_at' => $current['started_at'] ?? now()->toIso8601String()],
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

    private function key(string $regionKey): string
    {
        return "world-model:build:{$regionKey}";
    }
}
