<?php

declare(strict_types=1);

use App\Domain\Sources\Adapters\OvertureAdapter;

/*
|--------------------------------------------------------------------------
| Overture normalize() — pure, fixture-tested (conventions/09)
|--------------------------------------------------------------------------
|
| Fixture mirrors the `overturemaps download -f geojson --type=place` output
| shape. Unmapped categories (an insurance office) and nameless features are
| dropped; the raw category block is retained for re-normalisation.
|
*/

function overtureFixture(): array
{
    $data = json_decode(file_get_contents(__DIR__.'/../../Fixtures/Sources/overture-places-gamla-stan.geojson'), true);

    return $data['features'];
}

it('normalizes Overture features into typed candidates', function () {
    $candidates = new OvertureAdapter()->normalize(overtureFixture(), 'sv');

    $byName = collect($candidates)->keyBy('name');

    expect($byName['Under Kastanjen']['type'])->toBe('cafe')
        ->and($byName['Nobel Prize Museum']['type'])->toBe('local_museum')
        ->and($byName['Nobel Prize Museum']['alt_names'])->toContain('Nobelprismuseet')
        ->and($byName['Mårten Trotzigs Gränd']['type'])->toBe('notable_building')
        ->and($byName['Under Kastanjen']['source_tags']['categories']['primary'])->toBe('cafe');
});

it('drops unmapped categories and nameless features', function () {
    $names = array_column(new OvertureAdapter()->normalize(overtureFixture(), 'sv'), 'name');

    expect($names)->not->toContain('Some Insurance Office') // insurance_agency is not a place we rank
        ->and(count($names))->toBe(3);                       // the nameless restaurant is gone too
});
