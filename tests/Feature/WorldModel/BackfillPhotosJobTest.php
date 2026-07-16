<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Places\Services\FetchPlaceImages;
use App\Jobs\Ingest\BackfillPhotosJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The photos backfill, made deploy-durable
|--------------------------------------------------------------------------
|
| `photos:fetch` runs in the foreground and dies whenever the app container is
| restarted by a deploy — which is how the real backfill stalled twice. The
| queued chain re-dispatches itself on Horizon and drains across restarts.
|
*/

it('photos:fetch --queue hands the work to the durable job instead of looping', function () {
    Bus::fake();

    $this->artisan('photos:fetch --queue')->assertSuccessful();

    Bus::assertDispatched(BackfillPhotosJob::class);
});

it('re-dispatches itself while places still need examining', function () {
    // A place with a Commons tag a source will look at — so the batch has a candidate.
    Place::factory()->create(['source_tags' => ['osm' => ['wikimedia_commons' => 'File:Z.jpg']]]);

    Http::fake([
        // The tag resolves to no usable image: the place is examined but stays imageless,
        // so it is still a candidate and the chain must continue.
        'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => [], 'geosearch' => []]]),
        'query.wikidata.org/*' => Http::response(['results' => ['bindings' => []]]),
        '*.wikipedia.org/*' => Http::response(['query' => ['pages' => []]]),
        'graph.mapillary.com/*' => Http::response(['data' => []]),
        'api.openverse.org/*' => Http::response(['results' => []]),
    ]);

    Bus::fake();
    app(BackfillPhotosJob::class)->handle(app(FetchPlaceImages::class));

    Bus::assertDispatched(BackfillPhotosJob::class);
});

it('stops the chain when there is nothing left to examine', function () {
    // No places at all: a whole pass finds no candidates, so the chain ends rather than
    // re-queuing itself for ever on an empty world.
    Bus::fake();

    app(BackfillPhotosJob::class)->handle(app(FetchPlaceImages::class));

    Bus::assertNotDispatched(BackfillPhotosJob::class);
});
