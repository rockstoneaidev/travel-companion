<?php

declare(strict_types=1);

use App\Domain\Sources\Adapters\OvertureAdapter;
use App\Domain\Sources\Data\ScoutRequest;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| An extract is a snapshot of a bbox, and a region's bbox can change
|--------------------------------------------------------------------------
|
| Stockholm's did: widened from a 93 km² central slice to the whole municipality.
| The extract on disk still covered the old box, so Overture would have quietly
| returned the inner city and NOTHING for Farsta, Kista or Hässelby — and the
| region would have looked fully ingested.
|
| A source silently covering a fraction of its region is worse than a source that
| is absent, because absence is visible.
|
*/

it('refuses an extract that no longer covers its region', function () {
    Storage::fake('local');

    Storage::disk('local')->put('ingest/overture/widened.geojson', json_encode(['features' => []]));
    Storage::disk('local')->put('ingest/overture/widened.geojson.state', json_encode([
        'region' => 'widened', 'south' => 59.29, 'west' => 17.95, 'north' => 59.36, 'east' => 18.16,
    ]));

    // The region has since grown well beyond what was fetched.
    $widened = new ScoutRequest('widened', 59.220, 17.760, 59.430, 18.200, 'sv');

    expect(fn () => new OvertureAdapter()->search($widened))
        ->toThrow(RuntimeException::class, 'Re-fetch it');
});

it('accepts an extract that still covers its region', function () {
    Storage::fake('local');

    Storage::disk('local')->put('ingest/overture/ok.geojson', json_encode(['features' => []]));
    Storage::disk('local')->put('ingest/overture/ok.geojson.state', json_encode([
        'region' => 'ok', 'south' => 59.00, 'west' => 17.00, 'north' => 60.00, 'east' => 19.00,
    ]));

    $inside = new ScoutRequest('ok', 59.220, 17.760, 59.430, 18.200, 'sv');

    expect(new OvertureAdapter()->search($inside))->toBe([]);
});
