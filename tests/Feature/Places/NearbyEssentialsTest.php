<?php

declare(strict_types=1);

use App\Domain\Places\Data\Coordinates;
use App\Domain\Places\Models\Place;
use App\Domain\Places\Queries\NearbyEssentials;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function essentialPlace(float $lat, float $lng, string $type, string $domain, string $name): void
{
    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c;
    $place = Place::factory()->create([
        'name' => $name, 'type' => $type, 'type_domain' => $domain,
        'h3_index' => $cell, 'source_tags' => ['osm' => []],
    ]);
    DB::statement('UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [$lng, $lat, $place->id]);
}

it('returns the nearest practical places by distance, and nothing else', function () {
    essentialPlace(59.3291, 18.1779, 'toilet', 'practical', 'toilet near');
    essentialPlace(59.3305, 18.1800, 'pharmacy', 'practical', 'pharmacy far');
    essentialPlace(59.3292, 18.1780, 'restaurant', 'food_drink', 'Fjäderholmarnas krog');

    $out = app(NearbyEssentials::class)->near(new Coordinates(59.3291, 18.1779));
    $names = array_column($out, 'name');

    expect($names)->toContain('toilet near')
        ->and($names)->toContain('pharmacy far')
        // The discovery domain never leaks into the utility surface.
        ->and($names)->not->toContain('Fjäderholmarnas krog')
        // Nearest first — the top of the list is what matters in a hurry.
        ->and($out[0]['name'])->toBe('toilet near');
});

it('serves essentials over the web endpoint for a signed-in user', function () {
    essentialPlace(59.3291, 18.1779, 'toilet', 'practical', 'toilet near');

    $this->actingAs(User::factory()->create())
        ->getJson('/essentials?lat=59.3291&lng=18.1779')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'toilet');
});

it('requires a location', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/essentials')
        ->assertStatus(422);
});
