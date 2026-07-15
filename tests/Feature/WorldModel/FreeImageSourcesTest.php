<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Places\Models\PlaceImage;
use App\Domain\Places\Models\PlaceSourceId;
use App\Domain\Places\Services\FetchCommonsGeoImages;
use App\Domain\Places\Services\FetchOsmTagImages;
use App\Domain\Places\Services\FetchWikipediaImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E50 — the free trio that gets past 2.4% coverage
|--------------------------------------------------------------------------
|
| The Wikidata-only path reached only places with a Wikidata link. These three each catch a
| slice it could not: a Commons file tagged on the OSM object, the place's Wikipedia lead
| image, and — the widest net — a photo geotagged AT the place, needing nothing but its
| coordinate. Every path funnels through Commons imageinfo, so the "no attribution → not
| served" rule holds no matter which found the photo.
|
*/

/** The Commons imageinfo response for a resolvable file. */
function commonsInfoResponse(string $title): array
{
    return ['query' => ['pages' => [[
        'title' => $title,
        'imageinfo' => [[
            'thumburl' => 'https://upload.wikimedia.org/thumb/'.rawurlencode($title).'-800.jpg',
            'extmetadata' => [
                'Artist' => ['value' => '<a href="#">A Photographer</a>'],
                'LicenseShortName' => ['value' => 'CC BY-SA 4.0'],
            ],
        ]],
    ]]]];
}

it('takes the Commons file OSM tagged on a place with no Wikidata link', function () {
    $place = Place::factory()->create([
        'name' => 'Pontonjärparken',
        // The tag OsmAdapter used to discard — no wikidata, no wikipedia, just a photo.
        'source_tags' => ['osm' => ['leisure' => 'park', 'wikimedia_commons' => 'File:Pontonjärparken01.jpg']],
    ]);

    Http::fake([
        'commons.wikimedia.org/*' => Http::response(commonsInfoResponse('File:Pontonjärparken01.jpg')),
    ]);

    $result = app(FetchOsmTagImages::class)->fetchBatch();

    expect($result['images'])->toBe(1);

    $image = PlaceImage::query()->where('place_id', $place->id)->where('url', '<>', '')->first();
    expect($image->source)->toBe('osm_tag')
        ->and($image->attribution)->toBe('A Photographer')
        ->and($image->license)->toBe('CC BY-SA 4.0');
});

it('takes a place’s Wikipedia lead image when its Wikidata item has none', function () {
    $place = Place::factory()->create(['name' => 'Bergsgruvan']);
    PlaceSourceId::query()->create(['place_id' => $place->id, 'source' => 'wikipedia', 'external_id' => 'sv:Bergsgruvan']);

    Http::fake([
        // The article names its lead FILE (piprop=name), which we then resolve for licence.
        'sv.wikipedia.org/*' => Http::response(['query' => ['pages' => [[
            'title' => 'Bergsgruvan',
            'pageimage' => 'Bergsgruvan03.jpg',
        ]]]]),
        'commons.wikimedia.org/*' => Http::response(commonsInfoResponse('File:Bergsgruvan03.jpg')),
    ]);

    $result = app(FetchWikipediaImages::class)->fetchBatch();

    expect($result['images'])->toBe(1)
        ->and(PlaceImage::query()->where('place_id', $place->id)->where('source', 'wikipedia')->where('url', '<>', '')->exists())->toBeTrue();
});

it('finds a photo geotagged at a place that has no link or tag at all', function () {
    // The long tail: a neighbourhood park, no wikidata, no wikipedia, no commons tag. Only a
    // location — which is exactly what geosearch needs.
    $place = Place::factory()->create(['name' => 'Rosenlundsparken']);

    Http::fake([
        'commons.wikimedia.org/w/api.php?*list=geosearch*' => Http::response(['query' => ['geosearch' => [
            ['title' => 'File:Rosenlundsparken sommar.jpg'],
        ]]]),
        'commons.wikimedia.org/*' => Http::response(commonsInfoResponse('File:Rosenlundsparken sommar.jpg')),
    ]);

    $result = app(FetchCommonsGeoImages::class)->fetchBatch();

    expect($result['images'])->toBe(1)
        ->and(PlaceImage::query()->where('place_id', $place->id)->where('source', 'commons_geo')->where('url', '<>', '')->exists())->toBeTrue();
});

it('marks a place with nothing nearby so it is not re-searched forever', function () {
    $place = Place::factory()->create(['name' => 'Empty field']);

    Http::fake([
        'commons.wikimedia.org/*' => Http::response(['query' => ['geosearch' => []]]),
    ]);

    // First pass: nothing found → a geo:none marker, not a real image.
    app(FetchCommonsGeoImages::class)->fetchBatch();

    expect(PlaceImage::query()->where('place_id', $place->id)->where('file_name', 'geo:none')->exists())->toBeTrue();

    // Second pass: the marker excludes it, so it is not a candidate again — the cursor moved.
    $second = app(FetchCommonsGeoImages::class)->fetchBatch();
    expect($second['candidates'])->toBe(0);
});

it('never serves a Commons file it cannot attribute', function () {
    $place = Place::factory()->create([
        'source_tags' => ['osm' => ['wikimedia_commons' => 'File:Broken.jpg']],
    ]);

    Http::fake([
        // A file with no thumbnail — unrenderable. It must be skipped, not stored blank.
        'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => [[
            'title' => 'File:Broken.jpg',
            'imageinfo' => [['extmetadata' => []]],   // no thumburl
        ]]]]),
    ]);

    $result = app(FetchOsmTagImages::class)->fetchBatch();

    expect($result['images'])->toBe(0)
        ->and(PlaceImage::query()->where('place_id', $place->id)->where('url', '<>', '')->exists())->toBeFalse();
});
