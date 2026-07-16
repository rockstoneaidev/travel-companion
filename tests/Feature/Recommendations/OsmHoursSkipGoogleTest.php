<?php

declare(strict_types=1);

use App\Domain\Places\Enums\PlaceType;
use App\Domain\Places\Models\Place;
use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The OSM-hours cost lever (E50): stop paying Google to state the obvious
|--------------------------------------------------------------------------
|
| A place with a simple opening_hours tag, verified at a clear-cut time, is answered from
| OSM for free — Google is never called. The saving is the ABSENCE of the call, so that is
| exactly what the test asserts.
|
*/

function hoursPlace(string $name, float $lat, float $lng, ?string $hours): Place
{
    $place = Place::factory()->create([
        'name' => $name,
        'type' => PlaceType::Cafe,
        'type_domain' => 'food_drink',
        'h3_index' => DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text c', [$lng, $lat])->c,
        'source_tags' => ['osm' => array_filter(['opening_hours' => $hours]), 'wikidata' => []],
    ]);
    DB::statement('UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [$lng, $lat, $place->id]);

    return $place;
}

it('answers open/closed from OSM and never calls Google when the tag is clear-cut', function () {
    config()->set('services.google.maps_key', 'test-key');
    Http::fake(['places.googleapis.com/*' => Http::response(['currentOpeningHours' => ['openNow' => true]])]);

    // 11:00 Stockholm — clear of both the 09:00 open and the 20:00 close by more than the margin.
    $this->travelTo(CarbonImmutable::parse('2026-07-16 11:00:00', 'Europe/Stockholm'));

    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 180,
    ]);

    for ($i = 0; $i < 6; $i++) {
        hoursPlace("Café {$i}", 59.3103 + $i * 0.0008, 18.0227, 'Mo-Su 09:00-20:00');
    }

    $feed = app(RankSession::class)->feedFor(ExploreSessionData::fromModel($session));

    // The cafés are open per OSM, so they serve — and not one Google Places call was made.
    expect($feed)->not->toBeEmpty();
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'places.googleapis.com'));
});

it('still asks Google when OSM has no usable tag', function () {
    config()->set('services.google.maps_key', 'test-key');

    $searched = 0;
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => function () use (&$searched) {
            $searched++;

            return Http::response(['places' => [['id' => "ChIJx{$searched}"]]]);
        },
        'places.googleapis.com/v1/places/*' => Http::response(['currentOpeningHours' => ['openNow' => true]]),
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-07-16 11:00:00', 'Europe/Stockholm'));

    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 180,
    ]);

    // No opening_hours tag — the parser can't help, so Google is (correctly) consulted.
    for ($i = 0; $i < 6; $i++) {
        hoursPlace("Untagged {$i}", 59.3103 + $i * 0.0008, 18.0227, null);
    }

    app(RankSession::class)->feedFor(ExploreSessionData::fromModel($session));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'places.googleapis.com'));
});
