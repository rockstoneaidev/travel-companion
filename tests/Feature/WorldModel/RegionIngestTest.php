<?php

declare(strict_types=1);

use App\Domain\Sources\Data\IngestRegion;
use App\Domain\Sources\Models\SourceItem;
use App\Domain\Sources\Models\TileCacheState;
use App\Domain\Sources\Services\RegionIngest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Region ingest — E2's end-to-end slice against the real database
|--------------------------------------------------------------------------
|
| Overpass is faked with the recorded fixture; the H3 bucketing, the
| source_items upsert, and tile_cache_state bookkeeping are real (the test
| database runs PostGIS + h3-pg, conventions/11).
|
*/

function fakeOverpass(): void
{
    Http::fake([
        'lz4.overpass-api.de/*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/Sources/osm-overpass-gamla-stan.json')),
        ),
    ]);
}

it('ingests a region source into tiled, licensed source items', function () {
    fakeOverpass();

    $result = app(RegionIngest::class)->ingest(IngestRegion::named('stockholm'), 'osm');

    expect($result['candidates'])->toBeGreaterThan(5)
        ->and($result['tiles'])->toBeGreaterThan(0);

    $item = SourceItem::query()->where('source', 'osm')->where('external_id', 'way/8049504')->firstOrFail();

    expect($item->license->value)->toBe('odbl')
        ->and($item->storage_policy->value)->toBe('persistable')
        ->and($item->credibility_tier->value)->toBe('open')
        ->and($item->attribution)->toBe('© OpenStreetMap contributors, ODbL')
        ->and($item->source_adapter_version)->toBe('v1')
        ->and($item->payload['name'])->toBe('Storkyrkan')
        ->and($item->payload['type'])->toBe('church')
        ->and($item->h3_index)->toStartWith('88'); // res-8 cells share the 88 prefix

    // Gamla stan is one neighbourhood — everything lands in a handful of adjacent tiles.
    expect(TileCacheState::query()->where('source', 'osm')->count())->toBe($result['tiles'])
        ->and((int) TileCacheState::query()->where('source', 'osm')->sum('items_count'))->toBe($result['candidates']);
});

it('re-running the same region refreshes rows instead of duplicating them', function () {
    fakeOverpass();

    $ingest = app(RegionIngest::class);
    $region = IngestRegion::named('stockholm');

    $first = $ingest->ingest($region, 'osm');
    $countAfterFirst = SourceItem::query()->count();

    $second = $ingest->ingest($region, 'osm');

    expect(SourceItem::query()->count())->toBe($countAfterFirst)
        ->and($second['candidates'])->toBe($first['candidates']);
});

it('reports overture as unsupported (degraded, not failed) when no extract exists', function () {
    Storage::fake('local'); // the real disk may hold a downloaded extract

    $result = app(RegionIngest::class)->ingest(IngestRegion::named('stockholm'), 'overture');

    // Counts asserted individually rather than as a whole-array match: every ingest now
    // also reports `peak_mb`, because the memory profile is the thing that broke, and a
    // number nobody prints is a number nobody watches (RegionIngest::result()).
    expect($result['fetched'])->toBe(0)
        ->and($result['candidates'])->toBe(0)
        ->and($result['tiles'])->toBe(0)
        ->and($result)->toHaveKey('peak_mb');
});
