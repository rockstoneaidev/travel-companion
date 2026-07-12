<?php

declare(strict_types=1);

use App\Domain\Context\Actions\RecordContextEvent;
use App\Domain\Context\Data\NewContextEventData;
use App\Domain\Context\Models\ContextEvent;
use App\Domain\Places\Data\Coordinates;
use App\Domain\Places\Models\Place;
use App\Domain\Privacy\Services\HomeZone;
use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E17 — the declared home zone (PRD §16)
|--------------------------------------------------------------------------
|
| Phase 1's entire sensitive-zone scope, and it is not theoretical: Stockholm
| testing happens from the founder's actual home.
|
| Three rules, separate because they fail separately — no serving, no learning,
| no precise storage. The third is the one that matters most, because it is the
| only one that cannot be fixed after the fact: you cannot un-store a coordinate.
|
*/

/** A place in places_core, with real geography — local, so this file stands alone. */
function seedHomePlace(string $name, float $lat, float $lng, string $type, string $domain): void
{
    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c;

    $place = Place::factory()->create([
        'name' => $name, 'type' => $type, 'type_domain' => $domain,
        'facets' => ['scenic'], 'h3_index' => $cell,
        'source_tags' => ['osm' => [], 'wikidata' => []],
    ]);

    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [$lng, $lat, $place->id],
    );
}

function declareHomeAt(User $user, float $lat, float $lng, int $radius = 300): void
{
    DB::statement(
        'UPDATE users SET home_zone_center = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, home_zone_radius_meters = ? WHERE id = ?',
        [$lng, $lat, $radius, $user->id],
    );
}

it('suppresses nothing when no home zone is declared', function () {
    $user = User::factory()->create();

    $zone = HomeZone::forUser($user->id);

    // Opt-in, by construction. A zone nobody declared cannot hide anything.
    expect($zone->declared())->toBeFalse()
        ->and($zone->contains(59.3103, 18.0227))->toBeFalse();
});

it('knows what is inside the zone and what is just outside it', function () {
    $user = User::factory()->create();
    declareHomeAt($user, 59.3103, 18.0227, radius: 300);

    $zone = HomeZone::forUser($user->id);

    expect($zone->contains(59.3103, 18.0227))->toBeTrue()       // dead centre
        ->and($zone->contains(59.3113, 18.0227))->toBeTrue()    // ~111 m north
        ->and($zone->contains(59.3140, 18.0227))->toBeFalse();  // ~411 m north — outside
});

it('never serves an opportunity inside the home zone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-12 11:00:00', 'Europe/Stockholm'));

    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);

    seedHomePlace('Trekanten', 59.3117, 18.0206, 'lake', 'nature_landscape');
    seedHomePlace('Färgfabriken', 59.3120, 18.0190, 'gallery', 'arts_culture');

    // Home is right on top of Trekanten.
    declareHomeAt($user, 59.3117, 18.0206, radius: 300);

    $session = ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 180,
    ]);

    $recommendations = app(RankSession::class)->feedFor(ExploreSessionData::fromModel($session));

    $names = array_map(
        static fn ($r): string => $r->score_inputs['candidate']['name'],
        $recommendations,
    );

    // Being told about the lake at the end of your own street is not a travel
    // recommendation. Färgfabriken is 200 m further out and still offered.
    expect($names)->not->toContain('Trekanten');
});

it('leaves no trace of a suppressed place — not even in the funnel', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-12 11:00:00', 'Europe/Stockholm'));

    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);

    seedHomePlace('Trekanten', 59.3117, 18.0206, 'lake', 'nature_landscape');
    seedHomePlace('Färgfabriken', 59.3120, 18.0190, 'gallery', 'arts_culture');

    declareHomeAt($user, 59.3117, 18.0206, radius: 300);

    $session = ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 180,
    ]);

    app(RankSession::class)->feedFor(ExploreSessionData::fromModel($session));

    // Suppression happens BEFORE scoring, not as a filter on the way out. A place
    // that was ranked and then hidden still sits in the decision trace's funnel —
    // and a funnel that records what is near your home is a record of where you
    // live. It must not be scored at all.
    $traces = json_encode(DB::table('recommendations')->get());

    expect($traces)->not->toContain('Trekanten');
});

it('stores a coarse cell inside the zone, and never the coordinates', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id,
    ]);

    declareHomeAt($user, 59.3103, 18.0227, radius: 300);

    app(RecordContextEvent::class)(new NewContextEventData(
        exploreSessionId: $session->id,
        location: new Coordinates(59.3104, 18.0228),   // inside the zone
    ));

    $event = ContextEvent::query()->sole();

    // The pipeline gets coarse presence, which is all it needs. The coordinate is
    // never written — not for thirty days, not for thirty seconds. "We'll delete it
    // on schedule" is a different promise from "we never had it", and only one of
    // them survives a breach.
    expect($event->h3_index)->not->toBeNull()
        ->and($event->accuracy_meters)->toBeNull();

    $raw = DB::table('context_events')->where('id', $event->id)->first();
    expect($raw->location)->toBeNull();
});

it('still stores precise coordinates outside the zone — this is not a global downgrade', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id,
    ]);

    declareHomeAt($user, 59.3103, 18.0227, radius: 300);

    app(RecordContextEvent::class)(new NewContextEventData(
        exploreSessionId: $session->id,
        location: new Coordinates(59.3400, 18.0700),   // well outside
    ));

    $raw = DB::table('context_events')->where('user_id', $user->id)->first();

    // The product needs precise location to work. The home zone is a scalpel, not a
    // switch: it suppresses one place, not the feature.
    expect($raw->location)->not->toBeNull();
});
