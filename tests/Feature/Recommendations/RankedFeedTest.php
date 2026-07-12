<?php

declare(strict_types=1);

use App\Domain\Feedback\Models\RecommendationFeedback;
use App\Domain\Places\Models\Place;
use App\Domain\Profiles\Models\UserTasteProfile;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E7 end to end: session → ranked feed with traces → feedback moves taste
|--------------------------------------------------------------------------
*/

function seedRankablePlace(float $lat, float $lng, string $type, string $domain, string $name, array $facets): void
{
    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c;
    $place = Place::factory()->create([
        'name' => $name, 'type' => $type, 'type_domain' => $domain,
        'facets' => $facets, 'h3_index' => $cell,
        'source_tags' => ['osm' => [], 'wikidata' => []],
    ]);
    DB::statement('UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [$lng, $lat, $place->id]);
}

function startSession(User $user): ExploreSession
{
    $trip = Trip::factory()->create(['user_id' => $user->id]);

    return ExploreSession::factory()
        ->at(59.3250, 18.0700)
        ->create(['trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 180]);
}

beforeEach(function () {
    seedRankablePlace(59.3251, 18.0705, 'church', 'religious_sacred', 'Storkyrkan', ['history', 'architecture', 'spiritual']);
    seedRankablePlace(59.3248, 18.0712, 'cafe', 'food_drink', 'Chokladkoppen', ['food_drink', 'local_life']);
    seedRankablePlace(59.3260, 18.0690, 'viewpoint', 'nature_landscape', 'Utsikten', ['scenic']);
});

it('serves a ranked feed with full decision traces, then replays it stably', function () {
    $user = profilingConsent(User::factory()->create());
    $session = startSession($user);

    $response = $this->actingAs($user)->getJson("/api/v1/explore-sessions/{$session->id}/opportunities");
    $response->assertOk();

    $recommendations = Recommendation::query()->where('explore_session_id', $session->id)->orderBy('position')->get();

    expect($recommendations)->not->toBeEmpty()
        ->and($recommendations->first()->scores)->toHaveKeys(['personal_fit', 'uniqueness', 'temporal_urgency', 'novelty', 'confidence', 'composite', 'friction_raw'])
        ->and($recommendations->first()->score_inputs)->toHaveKeys(['candidate', 'raw', 'selection', 'reachability'])
        ->and($recommendations->first()->scoring_model_version)->toBe('v1');

    // A second request replays the stored feed — no re-rank, same order.
    $this->actingAs($user)->getJson("/api/v1/explore-sessions/{$session->id}/opportunities")->assertOk();
    expect(Recommendation::query()->where('explore_session_id', $session->id)->count())->toBe($recommendations->count());
});

it('moves facet weights on feedback and rejects other users', function () {
    $user = profilingConsent(User::factory()->create());
    $session = startSession($user);
    $this->actingAs($user)->getJson("/api/v1/explore-sessions/{$session->id}/opportunities")->assertOk();

    $recommendation = Recommendation::query()->where('explore_session_id', $session->id)->orderBy('position')->firstOrFail();
    $facets = $recommendation->score_inputs['candidate']['facets'];
    expect($facets)->not->toBeEmpty();

    // Saved: strong intent, η .15 toward 1 → 0.5 becomes 0.575.
    $this->actingAs($user)
        ->postJson("/api/v1/recommendations/{$recommendation->id}/feedback", ['event' => 'saved'])
        ->assertStatus(201);

    $weights = UserTasteProfile::for($user->id)->facet_weights;
    foreach ($facets as $facet) {
        expect($weights[$facet])->toEqualWithDelta(0.575, 0.0001);
    }

    // Dismissed pulls back down (η .25 toward 0): 0.575 → 0.4313.
    $this->actingAs($user)
        ->postJson("/api/v1/recommendations/{$recommendation->id}/feedback", ['event' => 'dismissed'])
        ->assertStatus(201);

    expect(UserTasteProfile::for($user->id)->facet_weights[$facets[0]])->toEqualWithDelta(0.4313, 0.001);

    // Another user cannot write feedback against this recommendation.
    $this->actingAs(profilingConsent(User::factory()->create()))
        ->postJson("/api/v1/recommendations/{$recommendation->id}/feedback", ['event' => 'saved'])
        ->assertForbidden();
});

it('batches ignored feedback for un-interacted cards on session end', function () {
    $user = profilingConsent(User::factory()->create());
    $session = startSession($user);
    $this->actingAs($user)->getJson("/api/v1/explore-sessions/{$session->id}/opportunities")->assertOk();

    $recommendations = Recommendation::query()->where('explore_session_id', $session->id)->orderBy('position')->get();
    expect($recommendations->count())->toBeGreaterThan(1);

    // Interact with the first; leave the rest untouched.
    $this->actingAs($user)
        ->postJson("/api/v1/recommendations/{$recommendations->first()->id}/feedback", ['event' => 'saved'])
        ->assertStatus(201);

    $this->actingAs($user)->postJson("/api/v1/explore-sessions/{$session->id}/end")->assertOk();

    $events = RecommendationFeedback::query()
        ->whereIn('recommendation_id', $recommendations->pluck('id'))
        ->pluck('event', 'recommendation_id');

    expect($events[$recommendations->first()->id]->value)->toBe('saved')          // interacted: never overwritten
        ->and($events[$recommendations->last()->id]->value)->toBe('ignored');     // untouched: batched on end
});
