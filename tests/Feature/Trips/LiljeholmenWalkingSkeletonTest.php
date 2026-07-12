<?php

declare(strict_types=1);

use App\Domain\Feedback\Models\RecommendationFeedback;
use App\Domain\Places\Models\Place;
use App\Domain\Profiles\Models\UserTasteProfile;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Trips\Models\ExploreSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M1 walking skeleton — the Liljeholmen slice, over the Inertia surface
|--------------------------------------------------------------------------
|
| The M1 milestone is defined as "a thin end-to-end slice on a phone in
| Liljeholmen". RankedFeedTest already proves the ranking pipeline over the
| JSON API. This test walks the *web* routes a phone actually hits, in order:
|
|   GET  /explore                     → start form (S2)
|   POST /explore                     → session opens, redirect to the feed
|   GET  /explore/{session}           → NOW feed (S1), server order preserved
|   GET  /opportunities/{opportunity} → detail (S4)
|   POST /recommendations/{id}/feedback → taste moves
|
| Origin is Liljeholmen T-bana (59.3103, 18.0227), walking, on a real budget.
|
*/

const LILJEHOLMEN_LAT = 59.3103;
const LILJEHOLMEN_LNG = 18.0227;

function seedPlaceNear(float $lat, float $lng, string $type, string $domain, string $name, array $facets): Place
{
    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c;

    $place = Place::factory()->create([
        'name' => $name,
        'type' => $type,
        'type_domain' => $domain,
        'facets' => $facets,
        'h3_index' => $cell,
        'source_tags' => ['osm' => [], 'wikidata' => []],
    ]);

    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [$lng, $lat, $place->id],
    );

    return $place;
}

beforeEach(function () {
    // Real Liljeholmen landmarks, at their real distances from the T-bana.
    seedPlaceNear(59.3117, 18.0206, 'gallery', 'arts_culture', 'Färgfabriken', ['art', 'architecture']);
    seedPlaceNear(59.3095, 18.0231, 'square', 'architecture_urban', 'Liljeholmstorget', ['local_life']);
    seedPlaceNear(59.3134, 18.0188, 'park', 'nature_landscape', 'Blomsterdalen', ['nature', 'scenic']);
});

it('walks the M1 slice: start form → session → feed → detail → feedback', function () {
    $user = User::factory()->create();

    // S2 — the start form is what you get with no session open.
    $this->actingAs($user)
        ->get('/explore')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('explore/index')
            ->has('travelModeOptions')
        );

    // Starting a session redirects straight into the feed.
    $start = $this->actingAs($user)->post('/explore', [
        'origin' => ['lat' => LILJEHOLMEN_LAT, 'lng' => LILJEHOLMEN_LNG],
        'travel_mode' => 'walk',
        'time_budget_minutes' => 90,
    ]);

    $session = ExploreSession::query()->where('user_id', $user->id)->sole();
    $start->assertRedirect("/explore/{$session->id}");

    // S1 — NOW. A walkable feed, in server order.
    $feed = $this->actingAs($user)->get("/explore/{$session->id}");
    $feed->assertOk();

    $props = $feed->viewData('page')['props'];
    $opportunities = $props['opportunities']['data'];
    expect($opportunities)->not->toBeEmpty();

    // Reach is derived server-side from budget × mode, not guessed on the client.
    $reachMeters = $props['session']['data']['reach_meters'];
    expect($reachMeters)->toBeGreaterThan(0);

    // Everything served is inside the reachability gate — nothing unreachable leaks through.
    foreach ($opportunities as $opportunity) {
        expect($opportunity['distance_meters'])->toBeLessThanOrEqual($reachMeters);
    }

    // Every served item carries a full decision trace (PRD §15).
    $recommendation = Recommendation::query()
        ->where('id', $opportunities[0]['recommendation_id'])
        ->sole();

    expect($recommendation->scoring_model_version)->not->toBeNull()
        ->and($recommendation->resolver_version)->not->toBeNull()
        ->and($recommendation->scores)->toHaveKeys([
            'personal_fit', 'uniqueness', 'novelty', 'temporal_urgency', 'confidence', 'composite',
        ])
        ->and($recommendation->score_inputs)->not->toBeEmpty();

    // S4 — detail.
    $this->actingAs($user)
        ->get("/opportunities/{$opportunities[0]['id']}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('opportunities/show'));

    // Feedback closes the loop and moves the taste profile.
    $this->actingAs($user)
        ->post("/recommendations/{$recommendation->id}/feedback", ['event' => 'visited'])
        ->assertRedirect();

    expect(RecommendationFeedback::query()->where('recommendation_id', $recommendation->id)->exists())->toBeTrue();

    $profile = UserTasteProfile::query()->where('user_id', $user->id)->sole();
    expect($profile->facet_weights)->not->toBeEmpty();
});

it('sends you back to your open session instead of a second start form', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/explore', [
        'origin' => ['lat' => LILJEHOLMEN_LAT, 'lng' => LILJEHOLMEN_LNG],
        'travel_mode' => 'walk',
        'time_budget_minutes' => 90,
    ]);

    $session = ExploreSession::query()->where('user_id', $user->id)->sole();

    $this->actingAs($user)->get('/explore')->assertRedirect("/explore/{$session->id}");
});

it('never serves an unreachable item on the minimum budget', function () {
    $user = User::factory()->create();

    // 15 minutes is the floor (config/trips.php). On foot that is a very short leash.
    $this->actingAs($user)->post('/explore', [
        'origin' => ['lat' => LILJEHOLMEN_LAT, 'lng' => LILJEHOLMEN_LNG],
        'travel_mode' => 'walk',
        'time_budget_minutes' => 15,
    ]);

    $session = ExploreSession::query()->where('user_id', $user->id)->sole();

    $feed = $this->actingAs($user)->get("/explore/{$session->id}");
    $feed->assertOk();

    $props = $feed->viewData('page')['props'];
    $reachMeters = $props['session']['data']['reach_meters'];

    foreach ($props['opportunities']['data'] as $opportunity) {
        expect($opportunity['distance_meters'])->toBeLessThanOrEqual($reachMeters);
    }
});
