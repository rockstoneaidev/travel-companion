<?php

declare(strict_types=1);

namespace App\Domain\Sources\Services;

use App\Domain\Places\Contracts\TileIndexer;
use App\Domain\Sources\Data\IngestRegion;
use App\Domain\Sources\Data\SourceDescriptor;
use App\Domain\Sources\Exceptions\StoragePolicyViolation;
use App\Domain\Sources\Models\TileCacheState;
use Illuminate\Support\Facades\DB;

/**
 * Region-bounded ingest (PRD §9.2 — scouts never crawl the world): runs each
 * source adapter over a bounded region, buckets candidates into res-8 tiles,
 * and upserts them as source items. Idempotent: re-running a region refreshes
 * rows in place (keyed on source + external_id), it never duplicates.
 */
final class RegionIngest
{
    public function __construct(
        private readonly SourceRegistry $registry,
        private readonly TileIndexer $tiles,
    ) {}

    /**
     * @return array{fetched: int, candidates: int, tiles: int}
     */
    public function ingest(IngestRegion $region, string $sourceKey): array
    {
        $descriptor = $this->registry->descriptor($sourceKey);

        // The SourceItem model guard enforces this on Eloquent saves; ingest
        // writes in bulk below it, so it re-asserts the same boundary.
        if (! $descriptor->storage->isStorable()) {
            throw StoragePolicyViolation::edgeOnlyPersistence($sourceKey);
        }

        $adapter = $this->registry->adapter($sourceKey);
        $request = $region->toScoutRequest();

        if (! $adapter->supports($request)) {
            return ['fetched' => 0, 'candidates' => 0, 'tiles' => 0];
        }

        $raw = $adapter->search($request);
        $candidates = $adapter->normalize($raw);

        if ($candidates === []) {
            return ['fetched' => count($raw), 'candidates' => 0, 'tiles' => 0];
        }

        $cells = $this->tiles->cellsFor(array_map(
            static fn (array $c): array => [$c['lat'], $c['lng']],
            $candidates,
        ));

        $now = now()->toIso8601String();
        $tileCounts = [];

        foreach (array_chunk(array_map(null, $candidates, $cells), 500) as $chunk) {
            $this->upsertChunk($chunk, $descriptor->key, $descriptor, $now);

            foreach ($chunk as [$candidate, $cell]) {
                $tileCounts[$cell] = ($tileCounts[$cell] ?? 0) + 1;
            }
        }

        $this->recordTileState($tileCounts, $descriptor->key, $descriptor->adapterVersion);

        return ['fetched' => count($raw), 'candidates' => count($candidates), 'tiles' => count($tileCounts)];
    }

    /** @param list<array{0: array<string, mixed>, 1: string}> $chunk */
    private function upsertChunk(array $chunk, string $sourceKey, SourceDescriptor $descriptor, string $now): void
    {
        $rows = [];
        $bindings = [];

        foreach ($chunk as [$candidate, $cell]) {
            $rows[] = '(gen_random_uuid(), ?, ?, ?, ?, ?, ?::jsonb, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?, ?, ?, ?, now(), now())';
            array_push(
                $bindings,
                $sourceKey,
                $candidate['external_id'],
                $descriptor->license->value,
                $descriptor->storage->value,
                $descriptor->credibilityTier->value,
                json_encode($candidate, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $candidate['lng'],
                $candidate['lat'],
                $cell,
                $descriptor->adapterVersion,
                $descriptor->attribution,
                $now,
            );
        }

        DB::statement(
            'INSERT INTO source_items
                (id, source, external_id, license, storage_policy, credibility_tier, payload, location, h3_index, source_adapter_version, attribution, retrieved_at, created_at, updated_at)
             VALUES '.implode(', ', $rows).'
             ON CONFLICT (source, external_id) WHERE external_id IS NOT NULL
             DO UPDATE SET
                payload = EXCLUDED.payload,
                location = EXCLUDED.location,
                h3_index = EXCLUDED.h3_index,
                source_adapter_version = EXCLUDED.source_adapter_version,
                attribution = EXCLUDED.attribution,
                retrieved_at = EXCLUDED.retrieved_at,
                updated_at = EXCLUDED.updated_at',
            $bindings,
        );
    }

    /** @param array<string, int> $tileCounts */
    private function recordTileState(array $tileCounts, string $sourceKey, string $adapterVersion): void
    {
        $now = now();

        TileCacheState::upsert(
            array_map(static fn (string $cell, int $count): array => [
                'h3_index' => $cell,
                'source' => $sourceKey,
                'source_adapter_version' => $adapterVersion,
                'last_scouted_at' => $now,
                'items_count' => $count,
                'created_at' => $now,
                'updated_at' => $now,
            ], array_keys($tileCounts), array_values($tileCounts)),
            uniqueBy: ['h3_index', 'source'],
            update: ['source_adapter_version', 'last_scouted_at', 'items_count', 'updated_at'],
        );
    }
}
