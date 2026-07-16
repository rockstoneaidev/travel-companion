<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Places\Models\PlaceImage;
use App\Domain\Places\Services\RefreshMapillaryImageUrls;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Mapillary URLs expire — keep them alive (E50)
|--------------------------------------------------------------------------
|
| A Mapillary thumbnail is a signed fbcdn URL that dies in ~4 weeks. We stored the durable
| image id (in file_name), so we re-fetch a fresh URL before the old one 404s. And we are
| careful never to blank a good photo just because Mapillary had a bad minute.
|
*/

function mapillaryImage(string $mapId, string $url, int $ageDays): PlaceImage
{
    return PlaceImage::query()->create([
        'place_id' => Place::factory()->create()->id,
        'source' => 'mapillary',
        'file_name' => "mapillary:{$mapId}",
        'url' => $url,
        'retrieved_at' => CarbonImmutable::now()->subDays($ageDays),
    ]);
}

beforeEach(fn () => config()->set('services.mapillary.token', 'test-token'));

it('re-fetches a fresh URL for an aging Mapillary photo, by its stored id', function () {
    $img = mapillaryImage('999', 'https://scontent.example/old?oe=EXPIRED', ageDays: 10);

    Http::fake([
        'graph.mapillary.com/999*' => Http::response(['thumb_1024_url' => 'https://scontent.example/fresh?oe=LATER']),
    ]);

    $result = app(RefreshMapillaryImageUrls::class)->refreshBatch();

    expect($result['refreshed'])->toBe(1)
        ->and($img->fresh()->url)->toBe('https://scontent.example/fresh?oe=LATER');
});

it('leaves recently-fetched URLs alone — nothing to refresh yet', function () {
    $img = mapillaryImage('1', 'https://scontent.example/recent', ageDays: 1);

    Http::fake();   // any call is a failure

    expect(app(RefreshMapillaryImageUrls::class)->refreshBatch()['refreshed'])->toBe(0)
        ->and($img->fresh()->url)->toBe('https://scontent.example/recent');
    Http::assertNothingSent();
});

it('clears a photo Mapillary has deleted, so the place shows its stripe not a dead link', function () {
    $img = mapillaryImage('404', 'https://scontent.example/gone', ageDays: 10);

    // A clean response with no image — the shot was removed.
    Http::fake(['graph.mapillary.com/404*' => Http::response(['id' => '404'])]);

    $result = app(RefreshMapillaryImageUrls::class)->refreshBatch();

    expect($result['cleared'])->toBe(1)
        ->and($img->fresh()->url)->toBe('');
});

it('never blanks a good photo when Mapillary is merely unreachable', function () {
    $img = mapillaryImage('500', 'https://scontent.example/stillgood', ageDays: 10);

    // Transport failure, not an answer.
    Http::fake(['graph.mapillary.com/500*' => Http::response('', 503)]);

    $result = app(RefreshMapillaryImageUrls::class)->refreshBatch();

    // Skipped, not cleared — the URL is left intact to retry next run.
    expect($result['skipped'])->toBe(1)
        ->and($result['cleared'])->toBe(0)
        ->and($img->fresh()->url)->toBe('https://scontent.example/stillgood');
});

it('does nothing without a Mapillary token', function () {
    config()->set('services.mapillary.token', '');
    mapillaryImage('1', 'https://x/y', ageDays: 30);
    Http::fake();

    expect(app(RefreshMapillaryImageUrls::class)->refreshBatch())->toMatchArray(['refreshed' => 0]);
    Http::assertNothingSent();
});
