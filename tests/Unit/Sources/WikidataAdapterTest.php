<?php

declare(strict_types=1);

use App\Domain\Sources\Adapters\WikidataAdapter;

/*
|--------------------------------------------------------------------------
| Wikidata normalize() — pure, fixture-tested (conventions/09)
|--------------------------------------------------------------------------
|
| The fixture is a recorded SPARQL bbox response for Gamla stan. A bbox
| pulls streets, alleys, and abstract entities — only P31 classes in the
| WikidataClassMap become candidates; local-language labels win.
|
*/

function wikidataFixture(): array
{
    $data = json_decode(file_get_contents(__DIR__.'/../../Fixtures/Sources/wikidata-sparql-gamla-stan.json'), true);

    return $data['results']['bindings'];
}

it('normalizes recorded SPARQL rows into typed candidates, grouping multi-class items', function () {
    $candidates = new WikidataAdapter()->normalize(wikidataFixture());

    expect($candidates)->not->toBeEmpty();

    $byId = collect($candidates)->keyBy('external_id');

    // Tre Kronor: P31 castle (Q23413) → castle
    expect($byId['Q147009']['type'])->toBe('castle')
        ->and($byId['Q147009']['name'])->toBe('Tre Kronor')
        ->and($byId['Q147009']['external_refs']['wikidata'])->toBe('Q147009');

    // Streets and alleys (Q79007/Q1251403) are not places — filtered out
    expect($byId->has('Q288166'))->toBeFalse('Riksgropen (archaeological? street) should only appear when a mapped class matches')
        ->and($byId->keys()->every(fn (string $qid): bool => str_starts_with($qid, 'Q')))->toBeTrue();
});

it('prefers the Swedish label and records the language', function () {
    $candidates = new WikidataAdapter()->normalize(wikidataFixture());
    $treKronor = collect($candidates)->firstWhere('external_id', 'Q147009');

    expect($treKronor['language'])->toBe('sv');
});

it('drops rows without coordinates or labels', function () {
    $candidates = new WikidataAdapter()->normalize([
        ['item' => ['value' => 'http://www.wikidata.org/entity/Q999999001'], 'class' => ['value' => 'http://www.wikidata.org/entity/Q23413']],
    ]);

    expect($candidates)->toBeEmpty();
});
