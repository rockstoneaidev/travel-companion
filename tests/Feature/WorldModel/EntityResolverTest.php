<?php

declare(strict_types=1);

use App\Domain\Places\Actions\MergePlaces;
use App\Domain\Places\Contracts\PlaceLookup;
use App\Domain\Places\Enums\MatchBand;
use App\Domain\Places\Models\Place;
use App\Domain\Places\Models\PlaceMatchDecision;
use App\Domain\Places\Models\PlaceSourceId;
use App\Domain\Places\Services\EntityResolver;
use App\Domain\Sources\Models\SourceItem;
use App\Enums\CredibilityTier;
use App\Enums\SourceLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Entity resolution — the v1 pipeline against the real database
|--------------------------------------------------------------------------
*/

const TILE = '8808866189fffff';

function makeItem(string $source, string $externalId, array $payload, CredibilityTier $tier = CredibilityTier::Open): SourceItem
{
    $item = SourceItem::factory()->create([
        'source' => $source,
        'external_id' => $externalId,
        'credibility_tier' => $tier,
        'license' => $source === 'wikidata' ? SourceLicense::Cc0 : SourceLicense::Odbl,
        'h3_index' => TILE,
        'payload' => [
            'alt_names' => [],
            'facets' => [],
            'source_tags' => [],
            'external_refs' => [],
            'taxonomy_version' => 1,
            ...$payload,
        ],
    ]);

    DB::statement(
        'UPDATE source_items SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [$payload['lng'], $payload['lat'], $item->id],
    );

    return $item->refresh();
}

it('merges OSM and Wikidata rows for the same place via the explicit wikidata ref', function () {
    makeItem('wikidata', 'Q1133075', [
        'name' => 'Sankt Nikolai kyrka', 'lat' => 59.32550, 'lng' => 18.07080,
        'type' => 'church', 'type_domain' => 'religious_sacred',
    ], CredibilityTier::Reference);

    makeItem('osm', 'way/8049504', [
        'name' => 'Storkyrkan', 'lat' => 59.32555, 'lng' => 18.07055,
        'type' => 'church', 'type_domain' => 'religious_sacred',
        'external_refs' => ['wikidata' => 'Q1133075'],
    ]);

    $stats = app(EntityResolver::class)->resolveTile(TILE);

    expect($stats)->toMatchArray(['items' => 2, 'created' => 1, 'explicit' => 1, 'merged' => 0]);

    $place = Place::query()->sole();

    // OSM name outranks (§3 stage 5); the Wikidata label survives as alternate.
    expect($place->name)->toBe('Storkyrkan')
        ->and($place->alt_names)->toContain('Sankt Nikolai kyrka')
        ->and($place->sourceIds()->pluck('source')->sort()->values()->all())->toBe(['osm', 'wikidata'])
        ->and(PlaceMatchDecision::query()->where('band', MatchBand::Explicit)->count())->toBe(1);
});

it('merges on a shared Wikipedia article when neither side carries a QID', function () {
    // The two sources spell the sitelink differently — OSM tags it `sv:Title`,
    // Wikidata gives the article URL — and they disagree on the name. Only the
    // shared article says they are the same church.
    makeItem('wikidata', 'Q1133075', [
        'name' => 'Sankt Nikolai kyrka', 'lat' => 59.32550, 'lng' => 18.07080,
        'type' => 'church', 'type_domain' => 'religious_sacred',
        'external_refs' => ['wikipedia' => 'https://sv.wikipedia.org/wiki/Storkyrkan'],
    ], CredibilityTier::Reference);

    makeItem('osm', 'way/8049504', [
        'name' => 'Storkyrkan', 'lat' => 59.32555, 'lng' => 18.07055,
        'type' => 'church', 'type_domain' => 'religious_sacred',
        'external_refs' => ['wikipedia' => 'sv:Storkyrkan'],
    ]);

    $stats = app(EntityResolver::class)->resolveTile(TILE);

    expect($stats)->toMatchArray(['items' => 2, 'created' => 1, 'explicit' => 1])
        ->and(Place::query()->count())->toBe(1);

    $decision = PlaceMatchDecision::query()->where('band', MatchBand::Explicit)->sole();
    expect($decision->signals['explicit'])->toBe('wikipedia:sv:Storkyrkan');
});

it('routes explicit joins to review when the points are over a kilometer apart', function () {
    makeItem('wikidata', 'Q99', [
        'name' => 'Vandaliserad kyrka', 'lat' => 59.3255, 'lng' => 18.0708,
        'type' => 'church', 'type_domain' => 'religious_sacred',
    ], CredibilityTier::Reference);

    makeItem('osm', 'node/1', [
        'name' => 'Vandaliserad kyrka', 'lat' => 59.3400, 'lng' => 18.0708, // ~1.6 km north
        'type' => 'church', 'type_domain' => 'religious_sacred',
        'external_refs' => ['wikidata' => 'Q99'],
    ]);

    $stats = app(EntityResolver::class)->resolveTile(TILE);

    expect($stats['review'])->toBe(1)
        ->and(Place::query()->count())->toBe(2); // serveable separately meanwhile
});

it('fuzzy-merges the same viewpoint from two sources without any shared id', function () {
    makeItem('osm', 'node/2', [
        'name' => 'Monteliusvägen', 'lat' => 59.31980, 'lng' => 18.06110,
        'type' => 'viewpoint', 'type_domain' => 'nature_landscape',
    ]);

    makeItem('overture', 'gers-abc123', [
        'name' => 'Monteliusvagen', 'lat' => 59.31985, 'lng' => 18.06130, // ~15 m, folded name
        'type' => 'viewpoint', 'type_domain' => 'nature_landscape',
    ]);

    $stats = app(EntityResolver::class)->resolveTile(TILE);

    expect($stats)->toMatchArray(['created' => 1, 'merged' => 1])
        ->and(Place::query()->count())->toBe(1)
        ->and(PlaceMatchDecision::query()->where('band', MatchBand::High)->sole()->signals['name_sim'])->toBeGreaterThan(0.9);
});

it('keeps identically named chain cafés apart and queues them for review', function () {
    makeItem('osm', 'node/3', [
        'name' => 'Espresso House', 'lat' => 59.3200, 'lng' => 18.0700,
        'type' => 'cafe', 'type_domain' => 'food_drink',
    ]);

    makeItem('overture', 'gers-espresso', [
        'name' => 'Espresso House', 'lat' => 59.3229, 'lng' => 18.0700, // ~320 m: inside blocking, past chain guard
        'type' => 'cafe', 'type_domain' => 'food_drink',
    ]);

    app(EntityResolver::class)->resolveTile(TILE);

    expect(Place::query()->count())->toBe(2)
        ->and(PlaceMatchDecision::query()->where('band', MatchBand::Review)->count())->toBe(1);
});

it('is idempotent: re-running a tile decides nothing twice', function () {
    makeItem('osm', 'node/4', [
        'name' => 'Ivar Los park', 'lat' => 59.3197, 'lng' => 18.0605,
        'type' => 'park', 'type_domain' => 'nature_landscape',
    ]);

    $resolver = app(EntityResolver::class);
    $first = $resolver->resolveTile(TILE);
    $second = $resolver->resolveTile(TILE);

    expect($first['items'])->toBe(1)
        ->and($second['items'])->toBe(0)
        ->and(Place::query()->count())->toBe(1)
        ->and(PlaceMatchDecision::query()->count())->toBe(1);
});

it('applies field-level survivorship: OSM geometry wins, tags union, conflicts recorded', function () {
    makeItem('wikidata', 'Q555', [
        'name' => 'Katarina kyrka', 'lat' => 59.31700, 'lng' => 18.07600,
        'type' => 'church', 'type_domain' => 'religious_sacred',
        'source_tags' => ['p31' => ['Q16970']],
    ], CredibilityTier::Reference);

    makeItem('osm', 'way/555', [
        'name' => 'Katarina kyrka', 'lat' => 59.31710, 'lng' => 18.07610,
        'type' => 'church', 'type_domain' => 'religious_sacred',
        'source_tags' => ['amenity' => 'place_of_worship'],
        'external_refs' => ['wikidata' => 'Q555'],
    ]);

    app(EntityResolver::class)->resolveTile(TILE);

    $place = Place::query()->sole();
    $point = DB::selectOne('SELECT ST_Y(location::geometry) AS lat FROM places_core WHERE id = ?', [$place->id]);

    expect((float) $point->lat)->toEqualWithDelta(59.31710, 0.00001) // OSM point won
        ->and($place->attribute_sources['geometry'])->toBe('osm')
        ->and(array_keys($place->source_tags))->toContain('wikidata', 'osm');
});

it('resolves merged-away place ids through the redirect at the PlaceLookup boundary', function () {
    $canonical = Place::factory()->create(['name' => 'Kanonisk']);
    $merged = Place::factory()->create(['name' => 'Dublett']);
    $mergedId = $merged->id;

    app(MergePlaces::class)($canonical, $merged);

    $found = app(PlaceLookup::class)->findMany([$mergedId, $canonical->id]);

    expect($found)->toHaveKeys([$mergedId, $canonical->id])
        ->and($found[$mergedId]->id)->toBe($canonical->id)
        ->and($found[$mergedId]->name)->toBe('Kanonisk')
        ->and(Place::query()->find($mergedId))->toBeNull()
        ->and(PlaceSourceId::query()->where('place_id', $mergedId)->count())->toBe(0);
});
