<?php

declare(strict_types=1);

use App\Domain\Places\Enums\PlaceType;
use App\Domain\Places\Models\Place;
use App\Domain\Places\Services\Scouts\PracticalScout;
use App\Domain\Sources\Enums\ScoutRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E39 — the practical scout
|--------------------------------------------------------------------------
|
| A pharmacy is never the answer to "what is worth your time here?" — but it is very much
| the answer to a different question the traveller sometimes urgently has. So it is scored
| by intent: near-range, quiet until relevant, never an "opportunity".
|
*/

it('surfaces the things you need and nothing else', function () {
    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(18.07, 59.32), 8)::text AS c')->c;

    $pharmacy = Place::factory()->create([
        'name' => 'Apoteket', 'type' => PlaceType::Pharmacy, 'type_domain' => 'practical', 'h3_index' => $cell,
    ]);
    $station = Place::factory()->create([
        'name' => 'Centralstation', 'type' => PlaceType::TransportHub, 'type_domain' => 'practical', 'h3_index' => $cell,
    ]);
    // A church in the same tile — emphatically not practical.
    Place::factory()->create([
        'name' => 'Storkyrkan', 'type' => PlaceType::Church, 'type_domain' => 'religious_sacred', 'h3_index' => $cell,
    ]);

    $names = array_column(app(PracticalScout::class)->candidatesForTile($cell), 'name');

    expect($names)->toContain('Apoteket')
        ->and($names)->toContain('Centralstation')
        ->and($names)->not->toContain('Storkyrkan');
});

it('is near-range, because a pharmacy 30 km ahead is a countdown, not a suggestion', function () {
    expect(app(PracticalScout::class)->range())->toBe(ScoutRange::Near);
});
