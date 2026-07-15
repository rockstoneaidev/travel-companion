<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Places\Models\PlaceImage;
use App\Domain\Places\Services\FetchMapillaryImages;
use App\Domain\Places\Services\FetchOpenverseImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E50 round two — Mapillary (coordinate) and Openverse (name, guarded)
|--------------------------------------------------------------------------
|
| Mapillary is coordinate-based and honest; Openverse is name-based and dangerous, so it is
| guarded hard. These pin both: Mapillary degrades to nothing without a token, and Openverse
| refuses a result that does not actually name the place — a paper stripe beats a photo of
| the wrong building.
|
*/

it('takes a street-level image at the place’s coordinate', function () {
    config()->set('services.mapillary.token', 'test-token');
    $place = Place::factory()->create(['name' => 'Medborgarplatsen']);

    Http::fake([
        'graph.mapillary.com/*' => Http::response(['data' => [[
            'id' => '1234567890',
            'thumb_1024_url' => 'https://scontent.mapillary.com/1234-1024.jpg',
        ]]]),
    ]);

    $result = app(FetchMapillaryImages::class)->fetchBatch();

    expect($result['images'])->toBe(1);
    $image = PlaceImage::query()->where('place_id', $place->id)->where('url', '<>', '')->first();
    expect($image->source)->toBe('mapillary')
        ->and($image->license)->toBe('CC BY-SA 4.0')
        ->and($image->attribution)->toBe('© Mapillary contributors');
});

it('does nothing at all without a Mapillary token', function () {
    config()->set('services.mapillary.token', '');
    Place::factory()->create();

    // No token is a supported state, not a failure. It touches no network and stores nothing.
    Http::fake();   // any call would be an error

    expect(app(FetchMapillaryImages::class)->fetchBatch())->toMatchArray(['candidates' => 0, 'images' => 0]);
    Http::assertNothingSent();
});

it('takes an Openverse photo only when the result actually names the place', function () {
    $place = Place::factory()->create(['name' => 'Rosenlundsparken']);

    Http::fake([
        'api.openverse.org/*' => Http::response(['results' => [[
            'id' => 'ov-1',
            'title' => 'Rosenlundsparken in summer',   // names the place — accepted
            'url' => 'https://example.org/rosenlund.jpg',
            'license' => 'by-sa',
            'license_version' => '4.0',
            'creator' => 'Some Photographer',
        ]]]),
    ]);

    $result = app(FetchOpenverseImages::class)->fetchBatch();

    expect($result['images'])->toBe(1);
    $image = PlaceImage::query()->where('place_id', $place->id)->where('url', '<>', '')->first();
    expect($image->source)->toBe('openverse')
        ->and($image->license)->toBe('BY-SA 4.0')
        ->and($image->attribution)->toBe('Some Photographer');
});

it('refuses an Openverse result that does not name the place — a wrong photo is worse than none', function () {
    $place = Place::factory()->create(['name' => 'Vasaparken']);

    Http::fake([
        'api.openverse.org/*' => Http::response(['results' => [[
            'id' => 'ov-2',
            'title' => 'A completely different park somewhere else',   // does NOT name it
            'url' => 'https://example.org/wrong.jpg',
            'license' => 'by',
            'creator' => 'X',
        ]]]),
    ]);

    $result = app(FetchOpenverseImages::class)->fetchBatch();

    // No image stored, and a sentinel so we do not re-search it — the guard held.
    expect($result['images'])->toBe(0)
        ->and(PlaceImage::query()->where('place_id', $place->id)->where('url', '<>', '')->exists())->toBeFalse()
        ->and(PlaceImage::query()->where('place_id', $place->id)->where('file_name', 'openverse:none')->exists())->toBeTrue();
});

it('never searches Openverse for a short or generic name', function () {
    // "Torg" (4 chars) is below the distinctiveness floor — a match would be a coin flip.
    Place::factory()->create(['name' => 'Torg']);

    Http::fake();   // any call is a failure

    expect(app(FetchOpenverseImages::class)->fetchBatch())->toMatchArray(['candidates' => 0, 'images' => 0]);
    Http::assertNothingSent();
});
