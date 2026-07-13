<?php

declare(strict_types=1);

use App\Domain\Sources\Adapters\WikidataAdapter;
use App\Domain\Sources\Contracts\PagedScoutSource;
use App\Domain\Sources\Data\IngestRegion;
use App\Domain\Sources\Data\ScoutRequest;
use App\Domain\Sources\Services\RegionIngest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The ingest must not hold a city in memory
|--------------------------------------------------------------------------
|
| It did, and it cost us the site. A whole-region ingest buffered the raw
| response, the normalized candidates, a lat/lng pair each, a cell each, and
| then a zipped COPY of the lot — five arrays of the same city, alive at once —
| and DATAtourisme built its share with `$objects = [...$objects, ...$page]`,
| which reallocates the entire accumulated array on every one of up to 500
| iterations.
|
| On the 3.7 GB staging box (~600 MB free) a Paris ingest killed the app
| container. Horizon runs inside that container, so it took travel.bergsten.net
| down with it — and the BuildRegionWorldModelJobs that had been failing as
| MaxAttemptsExceeded for a day were the same event seen from the queue's side.
|
| So the property under test is not "it works", it is "it works in bounded
| memory, whatever the size of the city". A test that only checked the rows
| landed would have passed the entire time this bug existed.
|
*/

/** A source that yields a big region in bounded pages, and records how it was consumed. */
final class FakePagedSource implements PagedScoutSource
{
    public int $pagesYielded = 0;

    public bool $searchWasCalled = false;

    public function __construct(
        private readonly int $pages = 40,
        private readonly int $perPage = 500,
    ) {}

    public function supports(ScoutRequest $request): bool
    {
        return true;
    }

    public function pages(ScoutRequest $request): iterable
    {
        for ($p = 0; $p < $this->pages; $p++) {
            $this->pagesYielded++;

            $page = [];

            for ($i = 0; $i < $this->perPage; $i++) {
                $n = $p * $this->perPage + $i;

                $page[] = [
                    'id' => "fake-{$n}",
                    'name' => "Place number {$n}",
                    'lat' => 59.31 + ($n % 100) * 0.0001,
                    'lng' => 18.02 + ($n % 100) * 0.0001,
                    // Realistic weight: source payloads are fat, and the memory bug was
                    // about payload volume, not row count.
                    'blob' => str_repeat('x', 2_000),
                ];
            }

            yield $page;
        }
    }

    public function search(ScoutRequest $request): array
    {
        // The buffered path. RegionIngest must never take it for a paged source — that
        // is the whole point of the contract.
        $this->searchWasCalled = true;

        return [];
    }

    public function normalize(array $raw, string $locale): array
    {
        return array_map(static fn (array $row): array => [
            'external_id' => $row['id'],
            'name' => $row['name'],
            'lat' => $row['lat'],
            'lng' => $row['lng'],
            'type' => 'lake',
            'type_domain' => 'nature_landscape',
            'facets' => [],
            'source_tags' => [],
            'external_refs' => [],
            'language' => $locale,
            'alt_names' => [],
        ], $raw);
    }

    public function ttl(): DateInterval
    {
        return new DateInterval('P30D');
    }
}

/**
 * Ingest a region through the fake.
 *
 * SourceRegistry::adapter() resolves the class out of the container, so binding the
 * fake in Wikidata's place is enough — the registry, the descriptor (license, storage
 * policy, attribution) and the real RegionIngest are all exercised unchanged. Only the
 * network is swapped out, which is the only part that should be.
 */
function ingestFake(FakePagedSource $source): array
{
    app()->instance(WikidataAdapter::class, $source);

    return app(RegionIngest::class)->ingest(IngestRegion::named('stockholm'), 'wikidata');
}

it('streams a paged source instead of buffering the region', function () {
    $source = new FakePagedSource(pages: 10, perPage: 200);

    $result = ingestFake($source);

    expect($result['candidates'])->toBe(2_000)
        ->and(DB::table('source_items')->count())->toBe(2_000)
        ->and($source->pagesYielded)->toBe(10)
        // The buffered path exists for the contract and the fixtures. The ingest must
        // not use it: a source that CAN stream must be streamed.
        ->and($source->searchWasCalled)->toBeFalse();
});

it('holds a page, not a city — peak memory does not scale with the region', function () {
    // 20,000 rows × ~2 KB of payload ≈ 40 MB of raw source data, which the old pipeline
    // would have held five times over (raw + candidates + pairs + cells + zip) before
    // writing a single row. Streaming holds ONE page: 500 rows.
    //
    // The assertion is deliberately loose — this is a memory bound, not a benchmark, and
    // a test that fails on GC timing teaches nobody anything. The old code could not have
    // passed it at any threshold this side of 200 MB.
    gc_collect_cycles();
    $before = memory_get_usage(true);

    $result = ingestFake(new FakePagedSource(pages: 40, perPage: 500));

    $growth = (memory_get_usage(true) - $before) / 1048576;

    expect($result['candidates'])->toBe(20_000)
        ->and(DB::table('source_items')->count())->toBe(20_000)
        ->and($growth)->toBeLessThan(32.0);

    // And the ingest reports its own peak, so a regression shows up in the ingest log
    // rather than in a dead container three weeks later.
    expect($result)->toHaveKey('peak_mb');
})->group('memory');
