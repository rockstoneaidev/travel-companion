<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Places\Queries\SearchGazetteer;
use App\Domain\Places\Services\GazetteerLoader;
use App\Domain\Places\Services\PlaceTypeahead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The gazetteer — search anywhere on Earth (PLAN-DRIVEN-INGESTION §3)
|--------------------------------------------------------------------------
|
| A global INDEX of settlement names, separate from places_core, so the planner
| can anchor a trip on a place we have not ingested yet (Kusmark) — which is
| exactly what then drives the ingestion (E48).
|
*/

function seedGazetteer(int $osmId, string $name, string $type, float $lat, float $lng, string $country = 'SE'): void
{
    DB::statement(
        'INSERT INTO gazetteer_places (osm_id, name, place_type, country_code, location, created_at, updated_at)
         VALUES (?, ?, ?, ?, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, now(), now())',
        [$osmId, $name, $type, $country, $lng, $lat],
    );
}

it('finds a settlement that is in no ingested region — the Kusmark case', function () {
    seedGazetteer(1, 'Kusmark', 'village', 64.75, 20.95);

    $out = app(SearchGazetteer::class)->search('kusmark');

    expect($out)->toHaveCount(1)
        ->and($out[0]->name)->toBe('Kusmark')
        ->and($out[0]->coordinates->lat)->toBeGreaterThan(64.0);
});

it('ranks a bigger settlement above a smaller one of the same name', function () {
    seedGazetteer(1, 'Boden', 'hamlet', 60.0, 15.0);
    seedGazetteer(2, 'Boden', 'city', 65.8, 21.7);

    expect(app(SearchGazetteer::class)->search('Boden')[0]->type)->toBe('city');
});

it('merges gazetteer settlements with places_core in the typeahead', function () {
    $place = Place::factory()->create([
        'name' => 'Kusmark Café', 'type' => 'cafe', 'type_domain' => 'food_drink',
        'h3_index' => DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(20.95, 64.75), 8)::text AS c')->c,
    ]);
    DB::statement('UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(20.95, 64.75), 4326)::geography WHERE id = ?', [$place->id]);

    seedGazetteer(1, 'Kusmark', 'village', 64.75, 20.95);

    $names = array_map(static fn ($s): string => $s->name, app(PlaceTypeahead::class)->search('kusmark', 8));

    expect($names)->toContain('Kusmark Café')   // the detailed place, from the core
        ->and($names)->toContain('Kusmark');     // the settlement itself, from the gazetteer
});

it('surfaces gazetteer results over the search endpoint', function () {
    seedGazetteer(1, 'Kusmark', 'village', 64.75, 20.95);

    $this->actingAs(User::factory()->create())
        ->getJson('/places/search?q=kusmark')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Kusmark')
        ->assertJsonPath('data.0.type', 'village');
});

it('loads settlements from OSM, keeps names + population, and is idempotent', function () {
    Http::fake([
        '*interpreter*' => Http::response(['elements' => [
            ['type' => 'node', 'id' => 100, 'lat' => 64.75, 'lon' => 20.95, 'tags' => ['name' => 'Kusmark', 'place' => 'village']],
            ['type' => 'node', 'id' => 101, 'lat' => 64.75, 'lon' => 20.96, 'tags' => ['name' => 'Skellefteå', 'place' => 'town', 'population' => '35,000']],
            // No name → not a search result, skipped.
            ['type' => 'node', 'id' => 102, 'lat' => 64.7, 'lon' => 20.9, 'tags' => ['place' => 'hamlet']],
        ]]),
    ]);

    expect(app(GazetteerLoader::class)->load('SE'))->toBe(2)
        ->and(DB::table('gazetteer_places')->count())->toBe(2)
        ->and(DB::table('gazetteer_places')->where('name', 'Skellefteå')->value('population'))->toBe(35000);

    // A re-load upserts by osm_id — it does not duplicate.
    app(GazetteerLoader::class)->load('SE');
    expect(DB::table('gazetteer_places')->count())->toBe(2);
});
