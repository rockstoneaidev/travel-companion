<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Trips\Enums\ExploreSessionStatus;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The home map (PRD §12.1, §12.4)
|--------------------------------------------------------------------------
|
| The dashboard map is a PRODUCT decision wearing a technical costume, so the
| tests are about the decision.
|
| It shows: you, what you kept, and what the ranker weighed and held back. It does
| NOT show the other 53,000 places in the world model. That map is a different
| product — one that says "here is everything, you decide", which is precisely the
| job this product took off the user. The easy way to lose that is for someone to
| "improve" the map by plotting nearby places, so the last test here fails loudly if
| they do.
|
*/

it('anchors the map on the last origin the user nominated, not on a tracked position', function () {
    $user = profilingAsked(User::factory()->create());
    $trip = Trip::factory()->create(['user_id' => $user->id]);

    // Phase 1 is foreground-only: the app knows where you are because you told it
    // when you opened a session (PRD §8). Nothing else is a location.
    ExploreSession::factory()->at(59.3293, 18.0686)->create([
        'trip_id' => $trip->id,
        'user_id' => $user->id,
        'status' => ExploreSessionStatus::Ended,
        'started_at' => now()->subHours(3),
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('map.origin.lat', fn (float $lat): bool => abs($lat - 59.3293) < 0.0001)
            ->where('map.origin.lng', fn (float $lng): bool => abs($lng - 18.0686) < 0.0001),
        );
});

it('draws no map at all for someone who has never opened a session', function () {
    $this->actingAs(profilingAsked(User::factory()->create()))
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('map.origin', null)->where('map.pins', []));
});

it('never plots the world model — only what the system has an opinion about', function () {
    $user = profilingAsked(User::factory()->create());
    $trip = Trip::factory()->create(['user_id' => $user->id]);

    ExploreSession::factory()->at(59.3293, 18.0686)->create([
        'trip_id' => $trip->id,
        'user_id' => $user->id,
        'status' => ExploreSessionStatus::Active,
        'started_at' => now()->subHour(),
    ]);

    // Three places, right next to the user, in the world model. None of them has been
    // recommended to them, kept by them, or passed over for them — so none of them
    // belongs on their map. "Nearby" is not an opinion.
    foreach ([[59.3295, 18.0688], [59.3298, 18.0690], [59.3301, 18.0692]] as [$lat, $lng]) {
        $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c;

        Place::factory()->create([
            'name' => 'Somewhere nearby',
            'type' => 'viewpoint',
            'type_domain' => 'nature_landscape',
            'facets' => ['scenic'],
            'h3_index' => $cell,
            'source_tags' => ['osm' => []],
            'location' => DB::raw("ST_SetSRID(ST_MakePoint({$lng}, {$lat}), 4326)::geography"),
        ]);
    }

    expect(DB::table('places_core')->count())->toBe(3);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('map.pins', []));
});

it('draws no map when the last session origin has been erased, rather than crashing', function () {
    $user = profilingAsked(User::factory()->create());
    $trip = Trip::factory()->create(['user_id' => $user->id]);

    $session = ExploreSession::factory()->at(59.3105, 18.0232)->create([
        'trip_id' => $trip->id,
        'user_id' => $user->id,
        'status' => ExploreSessionStatus::Ended,
        'started_at' => now()->subDay(),
    ]);

    /*
     * `origin` is nullable BY DESIGN, and two things null it on purpose: the privacy
     * erase (EraseTripLocations) and the 30-day retention pass that coarsens the
     * coordinate to its H3 cell before deleting it (PRD §16).
     *
     * The query guarded "no session at all" and then dereferenced `$session->origin->lat`
     * anyway — so the first user to exercise their right to erasure would have been met
     * with a 500 on the home screen. Punished, by a crash, for using the privacy feature.
     */
    DB::table('explore_sessions')->where('id', $session->id)->update(['origin' => null]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('map.origin', null));
});
