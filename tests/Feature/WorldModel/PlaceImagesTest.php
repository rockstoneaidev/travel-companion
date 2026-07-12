<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Places\Models\PlaceImage;
use App\Domain\Places\Models\PlaceSourceId;
use App\Domain\Places\Services\FetchCommonsImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('stores Commons images with per-file attribution, and sentinels no-image places', function () {
    $withImage = Place::factory()->create(['name' => 'Årsta kyrka']);
    $without = Place::factory()->create(['name' => 'Anonym plats']);
    PlaceSourceId::query()->create(['place_id' => $withImage->id, 'source' => 'wikidata', 'external_id' => 'Q10419704']);
    PlaceSourceId::query()->create(['place_id' => $without->id, 'source' => 'wikidata', 'external_id' => 'Q99999999']);

    Http::fake([
        'query.wikidata.org/*' => Http::response(['results' => ['bindings' => [[
            'item' => ['value' => 'http://www.wikidata.org/entity/Q10419704'],
            'image' => ['value' => 'http://commons.wikimedia.org/wiki/Special:FilePath/%C3%85rsta%20kyrka.jpg'],
        ]]]]),
        'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => [[
            'title' => 'File:Årsta kyrka.jpg',
            'imageinfo' => [[
                'thumburl' => 'https://upload.wikimedia.org/thumb/arsta-800.jpg',
                'extmetadata' => [
                    'Artist' => ['value' => '<a href="#">Mangan02</a>'],
                    'LicenseShortName' => ['value' => 'CC BY-SA 3.0'],
                ],
            ]],
        ]]]]),
    ]);

    $result = app(FetchCommonsImages::class)->fetchBatch();

    expect($result)->toMatchArray(['candidates' => 2, 'images' => 1]);

    $image = PlaceImage::query()->where('place_id', $withImage->id)->sole();
    expect($image->url)->toBe('https://upload.wikimedia.org/thumb/arsta-800.jpg')
        ->and($image->attribution)->toBe('Mangan02')             // tags stripped
        ->and($image->license)->toBe('CC BY-SA 3.0');

    // The no-image place got a sentinel so the batch cursor advances…
    expect(PlaceImage::query()->where('place_id', $without->id)->sole()->url)->toBe('')
        // …and a second batch finds nothing left to do.
        ->and(app(FetchCommonsImages::class)->fetchBatch()['candidates'])->toBe(0);
});
