<?php

declare(strict_types=1);

use App\Domain\Sources\Adapters\OsmAdapter;

/*
|--------------------------------------------------------------------------
| OSM normalize() — pure, fixture-tested (conventions/09)
|--------------------------------------------------------------------------
|
| The fixture is a recorded Overpass response for a Gamla stan sub-box.
| normalize() must map primary tags per TAXONOMY §3, keep the raw tags,
| carry explicit-ID refs for ER Stage 1, and drop unnamed non-practical noise.
|
*/

function osmFixture(): array
{
    $data = json_decode(file_get_contents(__DIR__.'/../../Fixtures/Sources/osm-overpass-gamla-stan.json'), true);

    return $data['elements'];
}

it('normalizes recorded Overpass elements into typed candidates', function () {
    $candidates = new OsmAdapter()->normalize(osmFixture());

    expect($candidates)->not->toBeEmpty();

    $byName = collect($candidates)->keyBy('name');

    // Storkyrkan: place_of_worship + building=church → church, wikidata ref kept
    expect($byName['Storkyrkan']['type'])->toBe('church')
        ->and($byName['Storkyrkan']['type_domain'])->toBe('religious_sacred')
        ->and($byName['Storkyrkan']['external_refs']['wikidata'])->toBe('Q1133075')
        ->and($byName['Storkyrkan']['facets'])->toContain('spiritual');

    // rune stone → archaeological site
    expect($byName['Upplands runinskrifter 53']['type'])->toBe('archaeological_site');

    // café stays a café
    expect($byName['Chokladkoppen']['type'])->toBe('cafe');
});

it('keeps every candidate typed, located, and versioned', function () {
    foreach (new OsmAdapter()->normalize(osmFixture()) as $candidate) {
        expect($candidate['type'])->not->toBeNull()
            ->and($candidate['lat'])->toBeFloat()
            ->and($candidate['lng'])->toBeFloat()
            ->and($candidate['taxonomy_version'])->toBe(1)
            ->and($candidate['source_tags'])->not->toBeEmpty()
            ->and($candidate['external_id'])->toMatch('#^(node|way|relation)/\d+$#');
    }
});

it('drops unnamed elements unless they are practical infrastructure', function () {
    $candidates = new OsmAdapter()->normalize([
        ['type' => 'node', 'id' => 1, 'lat' => 59.3, 'lon' => 18.0, 'tags' => ['tourism' => 'viewpoint']],
        ['type' => 'node', 'id' => 2, 'lat' => 59.3, 'lon' => 18.0, 'tags' => ['amenity' => 'toilets']],
    ]);

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]['type'])->toBe('toilet');
});
