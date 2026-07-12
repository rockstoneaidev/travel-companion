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
| The Decide gates, in the real pipeline (SCORING §2.1)
|--------------------------------------------------------------------------
|
| EvidenceGateTest proves the rule in isolation. This proves it actually bites:
| a Tier-D-only place is scouted, gated for reachability and scored — and still
| never reaches the feed, because existence was never established.
|
*/

function seedEvidencePlace(string $name, array $sourceTags, float $lat = 59.3251, float $lng = 18.0705): Place
{
    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c;

    $place = Place::factory()->create([
        'name' => $name,
        'type' => 'viewpoint',
        'type_domain' => 'nature_landscape',
        'facets' => ['scenic'],
        'h3_index' => $cell,
        'source_tags' => $sourceTags,
    ]);

    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [$lng, $lat, $place->id],
    );

    return $place;
}

function planFeed(): array
{
    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()
        ->at(59.3250, 18.0700)
        ->create(['trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 180]);

    return app(RankSession::class)->plan(ExploreSessionData::fromModel($session));
}

it('never serves a place whose only evidence is Tier-D — it becomes a lead', function () {
    seedEvidencePlace('Trusted viewpoint', ['osm' => [], 'wikidata' => []]);
    seedEvidencePlace('Blog-only ruin', ['some-travel-blog' => []]);

    $plan = planFeed();

    $servedNames = array_column($plan['picked'], 'name');
    $heldNames = array_column($plan['held'], 'name');

    expect($servedNames)->toContain('Trusted viewpoint')
        ->and($servedNames)->not->toContain('Blog-only ruin')
        ->and($heldNames)->toContain('Blog-only ruin');

    $held = collect($plan['held'])->firstWhere('name', 'Blog-only ruin');
    expect($held['hold']['reason'])->toBe('tier_d_only')
        ->and($held['hold']['status'])->toBe('corroboration_queue');
});

it('records why a held candidate was held, so the trace can answer for it', function () {
    seedEvidencePlace('Blog-only ruin', ['some-travel-blog' => []]);

    $held = planFeed()['held'];

    expect($held)->toHaveCount(1)
        ->and($held[0]['hold'])->toHaveKeys(['reason', 'status', 'confidence', 'tiers'])
        ->and($held[0]['hold']['tiers'])->toBe(['community']);
});

it('still serves a place that a Tier-D source merely corroborates', function () {
    seedEvidencePlace('Blogged and mapped', ['osm' => [], 'some-travel-blog' => []]);

    $plan = planFeed();

    expect(array_column($plan['picked'], 'name'))->toContain('Blogged and mapped')
        ->and($plan['held'])->toBeEmpty();
});
