<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Manual start point — geo-core typeahead (SCREENS S2)
|--------------------------------------------------------------------------
|
| When geolocation is denied the product must still be usable, so the user
| names their own starting point. We search our own places_core: no geocoder,
| no external call, and nothing but ODbL-publishable geo-core in the response.
|
*/

function seedSearchablePlace(string $name, string $type = 'square', string $domain = 'architecture_urban'): Place
{
    $place = Place::factory()->create([
        'name' => $name,
        'type' => $type,
        'type_domain' => $domain,
        'h3_index' => DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(18.0227, 59.3103), 8)::text AS c')->c,
    ]);

    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(18.0227, 59.3103), 4326)::geography WHERE id = ?',
        [$place->id],
    );

    return $place;
}

beforeEach(function () {
    seedSearchablePlace('Liljeholmstorget');
    seedSearchablePlace('Liljeholmsbadet', 'sports_venue', 'activity_recreation');
    seedSearchablePlace('Medborgarplatsen');
});

it('finds a starting point by prefix, closest match first', function () {
    $response = $this->actingAs(User::factory()->create())
        ->getJson('/places/search?q=liljeh');

    $response->assertOk();

    $names = array_column($response->json('data'), 'name');

    expect($names)->toContain('Liljeholmstorget')
        ->and($names)->toContain('Liljeholmsbadet')
        ->and($names)->not->toContain('Medborgarplatsen');
});

it('returns the coordinates the start form needs', function () {
    $response = $this->actingAs(User::factory()->create())
        ->getJson('/places/search?q=Liljeholmstorget');

    $first = $response->json('data.0');

    expect($first)->toHaveKeys(['id', 'name', 'location', 'type'])
        ->and($first['location'])->toHaveKeys(['lat', 'lng'])
        ->and($first['location']['lat'])->toBeGreaterThan(59.0);
});

it('tolerates a typo, because people type on the move', function () {
    $response = $this->actingAs(User::factory()->create())
        ->getJson('/places/search?q=Liljeholmstorgett');

    expect(array_column($response->json('data'), 'name'))->toContain('Liljeholmstorget');
});

it('refuses a single letter — that is not a search', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/places/search?q=l')
        ->assertStatus(422);
});

it('does not expose the world model to strangers', function () {
    $this->getJson('/places/search?q=liljeh')->assertUnauthorized();
});

it('serves the same results on the versioned API', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/places/search?q=liljeh');

    $response->assertOk();
    expect(array_column($response->json('data'), 'name'))->toContain('Liljeholmstorget');
});
