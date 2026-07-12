<?php

declare(strict_types=1);

use App\Domain\Places\Enums\PlaceType;
use App\Domain\Places\Taxonomy\MerimeeDenominationMap;
use App\Domain\Sources\Adapters\MerimeeAdapter;
use App\Domain\Sources\Data\ScoutRequest;

/*
|--------------------------------------------------------------------------
| Base Mérimée normalize() — pure, fixture-tested (conventions/09)
|--------------------------------------------------------------------------
|
| The fixture is a recorded Opendatasoft response for a Marais sub-box: real
| protected buildings, including the ones we deliberately drop.
|
*/

function merimeeFixture(): array
{
    return json_decode(file_get_contents(__DIR__.'/../../Fixtures/Sources/merimee-marais.json'), true);
}

function merimeeRequest(string $locale = 'fr'): ScoutRequest
{
    return new ScoutRequest('paris', 48.8500, 2.3400, 48.8650, 2.3700, $locale);
}

it('normalizes protected monuments into typed candidates', function () {
    $candidates = new MerimeeAdapter()->normalize(merimeeFixture(), 'fr');

    expect($candidates)->not->toBeEmpty();

    foreach ($candidates as $candidate) {
        expect($candidate['external_id'])->toStartWith('PA')   // the Mérimée reference
            ->and($candidate['language'])->toBe('fr')
            ->and($candidate['lat'])->toBeGreaterThan(48.0)
            ->and($candidate['name'])->not->toBe('');
    }
});

it('drops the protected buildings that are not opportunities', function () {
    // The Marais is full of listed `immeuble` — apartment blocks with a plaque.
    // They are Monuments Historiques and they are not somewhere to go. Serving
    // them would bury the Sainte-Chapelle under a thousand façades.
    $names = array_column(new MerimeeAdapter()->normalize(merimeeFixture(), 'fr'), 'name');

    expect($names)->not->toContain('Immeuble');
});

it('keeps a hôtel particulier — a townhouse, not a hotel', function () {
    $names = array_column(new MerimeeAdapter()->normalize(merimeeFixture(), 'fr'), 'name');

    expect($names)->toContain('Hôtel Le Pelletier de Souzy');
});

it('names a monument by its title, never by its category', function () {
    // `denomination` is "église"; using it as the name would give a hundred
    // places all called "église". The editorial title is the actual name.
    $candidates = new MerimeeAdapter()->normalize([[
        'reference' => 'PA00086250',
        'titre_editorial_de_la_notice' => 'Église Saint-Gervais-Saint-Protais',
        'denomination_de_l_edifice' => 'église',
        'coordonnees_au_format_wgs84' => ['lat' => 48.8556, 'lon' => 2.3543],
    ]], 'fr');

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]['name'])->toBe('Église Saint-Gervais-Saint-Protais')
        ->and($candidates[0]['type'])->toBe('church');
});

it('drops a record with no stable reference — nothing to resolve on', function () {
    $candidates = new MerimeeAdapter()->normalize([[
        'reference' => '',
        'titre_editorial_de_la_notice' => 'Anonyme',
        'denomination_de_l_edifice' => 'château',
        'coordonnees_au_format_wgs84' => ['lat' => 48.85, 'lon' => 2.35],
    ]], 'fr');

    expect($candidates)->toBeEmpty();
});

it('has nothing to say about Sweden', function () {
    // A French national registry should not be round-tripped for Stockholm.
    $adapter = new MerimeeAdapter;

    expect($adapter->supports(merimeeRequest('fr')))->toBeTrue()
        ->and($adapter->supports(merimeeRequest('sv')))->toBeFalse();
});

it('maps the head noun when Mérimée qualifies a denomination', function () {
    // "église paroissiale", "chapelle funéraire" — the head word carries the type.
    expect(MerimeeDenominationMap::map('église paroissiale'))->toBe(PlaceType::Church)
        ->and(MerimeeDenominationMap::map('chapelle funéraire'))->toBe(PlaceType::Chapel)
        ->and(MerimeeDenominationMap::map('château fort'))->toBe(PlaceType::Castle)
        // ...but never a substring match: this is a HOUSE, not a castle.
        ->and(MerimeeDenominationMap::map('maison du gardien du château'))->toBeNull();
});
