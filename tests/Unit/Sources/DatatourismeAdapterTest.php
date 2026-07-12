<?php

declare(strict_types=1);

use App\Domain\Places\Enums\PlaceType;
use App\Domain\Places\Taxonomy\DatatourismeTypeMap;
use App\Domain\Sources\Adapters\DatatourismeAdapter;

/*
|--------------------------------------------------------------------------
| DATAtourisme normalize() — pure, fixture-tested (conventions/09)
|--------------------------------------------------------------------------
|
| The fixture is a recorded API response for a Marais sub-box: the Centre
| Pompidou, a Holocaust memorial, a swimming pool, and the boutiques and hotels
| a tourism board lists that this product should not.
|
*/

function datatourismeFixture(): array
{
    return json_decode(file_get_contents(__DIR__.'/../../Fixtures/Sources/datatourisme-marais.json'), true);
}

it('normalizes tourism-board POIs into typed candidates', function () {
    $candidates = app(DatatourismeAdapter::class)->normalize(datatourismeFixture(), 'fr');

    expect($candidates)->not->toBeEmpty();

    foreach ($candidates as $candidate) {
        expect($candidate['language'])->toBe('fr')
            ->and($candidate['external_id'])->not->toBe('')
            ->and($candidate['lat'])->toBeGreaterThan(48.0);
    }
});

it('types the Centre Pompidou as a museum, despite the ontology list being unordered', function () {
    // The real trap. Its classes arrive as
    //   [LocalBusiness, PlaceOfInterest, Museum, CulturalSite, PointOfInterest]
    // — generic last, specific in the middle. Reading the list in either
    // direction gives "cultural site" or "local business". Only scanning our own
    // priority map gives "museum".
    $candidates = app(DatatourismeAdapter::class)->normalize(datatourismeFixture(), 'fr');

    $pompidou = collect($candidates)->firstWhere('name', 'Centre Pompidou');

    expect($pompidou)->not->toBeNull()
        ->and($pompidou['type'])->toBe('history_museum');
});

it('prefers the specific class over the generic one, whatever the order', function () {
    // Same set, shuffled: the answer must not depend on the order.
    $types = ['LocalBusiness', 'PlaceOfInterest', 'Museum', 'CulturalSite', 'PointOfInterest'];

    expect(DatatourismeTypeMap::map($types))->toBe(PlaceType::HistoryMuseum)
        ->and(DatatourismeTypeMap::map(array_reverse($types)))->toBe(PlaceType::HistoryMuseum)
        ->and(DatatourismeTypeMap::map(['CulturalSite', 'RemembranceSite']))->toBe(PlaceType::Memorial);
});

it('does not serve hotels — a tourism board lists them, we do not', function () {
    // Of a 500-POI Paris sample, 61 were accommodation. Mapping them would make
    // one in four recommendations somewhere to sleep rather than somewhere to go.
    expect(DatatourismeTypeMap::map(['PointOfInterest', 'Accommodation', 'Hotel', 'HotelTrade']))->toBeNull()
        ->and(DatatourismeTypeMap::map(['PointOfInterest', 'ServiceProvider']))->toBeNull()
        ->and(DatatourismeTypeMap::map(['PointOfInterest', 'PlaceOfInterest']))->toBeNull();
});

it('keeps the tourism board description as grounded evidence, never as a fact', function () {
    // The curation pipeline drafts a claim FROM this (CURATION §3). It is
    // open-licensed evidence with an author, not something we assert ourselves.
    $candidates = app(DatatourismeAdapter::class)->normalize(datatourismeFixture(), 'fr');

    $withDescription = collect($candidates)->first(
        fn (array $c): bool => ($c['source_tags']['description'] ?? null) !== null,
    );

    expect($withDescription)->not->toBeNull()
        ->and($withDescription['source_tags'])->toHaveKey('published_by');
});

it('reads the French label, because that is the one that exists', function () {
    $candidates = app(DatatourismeAdapter::class)->normalize([[
        'uuid' => 'abc-123',
        'label' => ['@fr' => 'Musée de la chasse et de la nature'],
        'type' => ['PointOfInterest', 'Museum'],
        'isLocatedAt' => [['geo' => ['latitude' => 48.8626, 'longitude' => 2.3596]]],
    ]], 'fr');

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]['name'])->toBe('Musée de la chasse et de la nature');
});
