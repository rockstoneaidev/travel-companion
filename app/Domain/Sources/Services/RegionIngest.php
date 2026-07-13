<?php

declare(strict_types=1);

namespace App\Domain\Sources\Services;

use App\Domain\Places\Contracts\TileIndexer;
use App\Domain\Sources\Contracts\PagedScoutSource;
use App\Domain\Sources\Contracts\ScoutSource;
use App\Domain\Sources\Data\IngestRegion;
use App\Domain\Sources\Data\ScoutRequest;
use App\Domain\Sources\Data\SourceDescriptor;
use App\Domain\Sources\Exceptions\StoragePolicyViolation;
use App\Domain\Sources\Models\TileCacheState;
use Illuminate\Support\Facades\DB;

/**
 * Region-bounded ingest (PRD §9.2 — scouts never crawl the world): runs each
 * source adapter over a bounded region, buckets candidates into res-8 tiles,
 * and upserts them as source items. Idempotent: re-running a region refreshes
 * rows in place (keyed on source + external_id), it never duplicates.
 *
 * MEMORY IS THE CONSTRAINT HERE, and it is the one that actually bit: this used to
 * buffer an entire region — raw response, candidates, lat/lng pairs, cells, and a
 * zipped copy — and killed the app container on a 3.7 GB box mid-Paris. It now
 * streams: one page in, written, freed. See {@see PagedScoutSource}.
 */
final class RegionIngest
{
    public function __construct(
        private readonly SourceRegistry $registry,
        private readonly TileIndexer $tiles,
    ) {}

    /** Rows per INSERT, and therefore the ceiling on how many candidates are resident. */
    private const UPSERT_CHUNK = 500;

    /**
     * @param  ?ScoutRequest  $box  One grid cell of the region, or null for the whole thing.
     *                              A box is the unit of WORK and the unit of PERSISTENCE
     *                              (IngestRegion::boxes()): what this call fetches, it writes
     *                              before returning, so a job that dies costs one box rather
     *                              than an hour of unwritten elements.
     * @return array{fetched: int, candidates: int, tiles: int, peak_mb: float}
     */
    public function ingest(IngestRegion $region, string $sourceKey, ?ScoutRequest $box = null): array
    {
        $descriptor = $this->registry->descriptor($sourceKey);

        // The SourceItem model guard enforces this on Eloquent saves; ingest
        // writes in bulk below it, so it re-asserts the same boundary.
        if (! $descriptor->storage->isStorable()) {
            throw StoragePolicyViolation::edgeOnlyPersistence($sourceKey);
        }

        $adapter = $this->registry->adapter($sourceKey);
        $request = $box ?? $region->toScoutRequest();

        if (! $adapter->supports($request)) {
            return $this->result(0, 0, 0);
        }

        /*
         * ===================================================================
         *  STREAMED, not buffered. This loop is the fix.
         * ===================================================================
         *
         * It used to be five arrays deep, all alive at once: the raw response, the
         * normalized candidates, a lat/lng pair per candidate, a cell per candidate,
         * and then `array_map(null, $candidates, $cells)` — a full zipped COPY of the
         * lot — before array_chunk made a sixth. For a Paris-sized region that is
         * hundreds of megabytes of PHP arrays to write a few thousand rows, and on a
         * box with 600 MB free it killed the container (and, since Horizon lives in
         * that container, the site with it).
         *
         * Now: one page in, normalized, written in chunks, freed. Peak memory is a
         * page — not a region — so a city twice the size costs the same peak. That is
         * the property worth having; a bigger box would only move the wall.
         */
        $fetched = 0;
        $candidateCount = 0;
        $tileCounts = [];
        $now = now()->toIso8601String();

        foreach ($this->pagesOf($adapter, $request) as $page) {
            $fetched += count($page);

            $candidates = $adapter->normalize($page, $request->locale);

            // The raw page is dead the moment it is normalized, and holding it while we
            // write is how the peak doubles for no reason.
            unset($page);

            if ($candidates === []) {
                continue;
            }

            $candidateCount += count($candidates);

            foreach (array_chunk($candidates, self::UPSERT_CHUNK) as $chunk) {
                // Cells for THIS chunk only. Computing them for the whole region up front
                // meant a full second array of the same length, for no gain — the H3
                // lookup is a batched Postgres call either way.
                $cells = $this->tiles->cellsFor(array_map(
                    static fn (array $c): array => [$c['lat'], $c['lng']],
                    $chunk,
                ));

                $this->upsertChunk($chunk, $cells, $descriptor, $now);

                foreach ($cells as $cell) {
                    $tileCounts[$cell] = ($tileCounts[$cell] ?? 0) + 1;
                }
            }

            unset($candidates);
        }

        if ($tileCounts !== []) {
            $this->recordTileState($tileCounts, $descriptor->key, $descriptor->adapterVersion);
        }

        return $this->result($fetched, $candidateCount, count($tileCounts));
    }

    /**
     * One page, or many.
     *
     * A source that cannot page (OSM — already chunked spatially into boxes, so its
     * search IS a page) yields exactly one. Wrapping it in an array rather than
     * special-casing the caller keeps the loop above honest about what it is: a stream
     * that happens to be one element long.
     *
     * @return iterable<int, list<array<string, mixed>>>
     */
    private function pagesOf(ScoutSource $adapter, ScoutRequest $request): iterable
    {
        if ($adapter instanceof PagedScoutSource) {
            return $adapter->pages($request);
        }

        return [$adapter->search($request)];
    }

    /**
     * Peak RSS is reported with every ingest, on purpose.
     *
     * The memory profile is the thing that broke, and a number nobody prints is a
     * number nobody watches. It goes into the job's log line and the command's output,
     * so the day someone re-introduces a buffered adapter, the regression is visible in
     * the ingest log rather than three weeks later in a dead container.
     *
     * @return array{fetched: int, candidates: int, tiles: int, peak_mb: float}
     */
    private function result(int $fetched, int $candidates, int $tiles): array
    {
        return [
            'fetched' => $fetched,
            'candidates' => $candidates,
            'tiles' => $tiles,
            'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     * @param  list<string>  $cells  parallel to $chunk
     */
    private function upsertChunk(array $chunk, array $cells, SourceDescriptor $descriptor, string $now): void
    {
        $sourceKey = $descriptor->key;
        $rows = [];
        $bindings = [];

        foreach ($chunk as $i => $candidate) {
            $cell = $cells[$i];

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
