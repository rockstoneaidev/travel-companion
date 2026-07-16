<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Places\Models\PlaceImage;
use App\Domain\Places\Services\FetchPlaceImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('does not let one failing source stop the whole backfill', function () {
    // The real stall: a Mapillary call with a truncated token threw, and because the batch
    // ran unguarded, that one 401 killed the photos phase — 50k places left unprocessed.
    config()->set('services.mapillary.token', 'bad-token');
    Place::factory()->create(['source_tags' => ['osm' => ['wikimedia_commons' => 'File:X.jpg']]]);

    // Mapillary blows up (as a bad token would). Every other source answers cleanly.
    Http::fake([
        'graph.mapillary.com/*' => Http::response('unauthorized', 401),
        'commons.wikimedia.org/*' => Http::response(['query' => ['geosearch' => [], 'pages' => []]]),
        'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]]),
        '*.wikipedia.org/*' => Http::response(['query' => ['pages' => []]]),
        'api.openverse.org/*' => Http::response(['results' => []]),
    ]);

    // The orchestrator completes rather than throwing — the Mapillary 401 is reported and
    // swallowed, and the batch's candidate count still comes back so the phase keeps going.
    $result = app(FetchPlaceImages::class)->fetchBatch();

    expect($result)->toHaveKeys(['candidates', 'images']);
});

it('still stores the images the working sources found when a later one throws', function () {
    config()->set('services.mapillary.token', 'bad-token');

    // Place A has a Commons tag an early source (osm_tag) can resolve. Place B has nothing,
    // so it flows all the way down to Mapillary — which throws (bad token).
    $withTag = Place::factory()->create(['source_tags' => ['osm' => ['wikimedia_commons' => 'File:Good.jpg']]]);
    Place::factory()->create(['source_tags' => ['osm' => []]]);

    Http::fake([
        // Commons: resolve A's file (osm_tag), but geosearch for B finds nothing.
        'commons.wikimedia.org/*geosearch*' => Http::response(['query' => ['geosearch' => []]]),
        'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => [[
            'title' => 'File:Good.jpg',
            'imageinfo' => [['thumburl' => 'https://upload/good-800.jpg', 'extmetadata' => ['LicenseShortName' => ['value' => 'CC BY-SA 4.0']]]],
        ]]]]),
        'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]]),
        '*.wikipedia.org/*' => Http::response(['query' => ['pages' => []]]),
        'api.openverse.org/*' => Http::response(['results' => []]),
        // Mapillary explodes on place B.
        'graph.mapillary.com/*' => Http::response('unauthorized', 401),
    ]);

    app(FetchPlaceImages::class)->fetchBatch();

    // A's image was stored despite Mapillary throwing on B — the batch didn't abort.
    expect(PlaceImage::query()->where('place_id', $withTag->id)->where('url', '<>', '')->exists())->toBeTrue();
});
