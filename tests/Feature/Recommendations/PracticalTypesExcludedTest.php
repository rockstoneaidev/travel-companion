<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase-2 practical types never surface in the Phase-1 feed
|--------------------------------------------------------------------------
|
| The taxonomy marks `practical` (toilet, charging point, pharmacy, shelter,
| transport hub) as Phase 2 — "no Phase 1 scout uses them". They were ingested
| from OSM anyway, and a session that reached Fjäderholmarna with its budget
| almost spent got served three toilets and nothing else: a toilet is the only
| thing whose near-zero dwell fits a near-empty budget.
|
*/

function seedTypedPlace(float $lat, float $lng, string $type, string $domain, string $name): void
{
    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c;
    $place = Place::factory()->create([
        'name' => $name, 'type' => $type, 'type_domain' => $domain,
        'h3_index' => $cell, 'source_tags' => ['osm' => [], 'wikidata' => []],
    ]);
    DB::statement('UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [$lng, $lat, $place->id]);
}

it('never serves Phase-2 practical types in the feed, only the real places', function () {
    // Fjäderholmarna in miniature: a restaurant and a museum, with toilets and a charger
    // right on top of them.
    seedTypedPlace(59.3291, 18.1779, 'restaurant', 'food_drink', 'Fjäderholmarnas krog');
    seedTypedPlace(59.3292, 18.1780, 'local_museum', 'museum_gallery', 'Allmogebåtarna');
    seedTypedPlace(59.3290, 18.1778, 'toilet', 'practical', 'toilet A');
    seedTypedPlace(59.3293, 18.1781, 'toilet', 'practical', 'toilet B');
    seedTypedPlace(59.3289, 18.1777, 'charging_point', 'practical', 'charger');

    $user = profilingConsent(User::factory()->create());
    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->at(59.3291, 18.1779)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 180,
    ]);

    $items = app(RankSession::class)->browse(ExploreSessionData::fromModel($session), 50)['items'];
    $types = array_column($items, 'type');

    // The real places are candidates; the utility amenities are not, at any budget.
    expect($types)->toContain('restaurant')
        ->and($types)->not->toContain('toilet')
        ->and($types)->not->toContain('charging_point');
});
