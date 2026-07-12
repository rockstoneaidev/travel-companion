<?php

declare(strict_types=1);

use App\Domain\Curation\Enums\CurationStatus;
use App\Domain\Curation\Models\CuratedItem;
use App\Domain\Opportunities\Enums\OpportunityKind;
use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Opportunities\Queries\ListOpportunitiesForSession;
use App\Domain\Places\Models\Place;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| A published pack must actually be heard (CURATION §3)
|--------------------------------------------------------------------------
|
| Two bugs conspired to make curated content unreachable in a real feed, both
| found only by driving the live pipeline after the stockholm pack was
| approved and published:
|
|   1. RankSession::dedupe() kept the FIRST scout's candidate and dropped every
|      other field from later ones. CuratedScout runs last, so any place another
|      scout had already seen (a lake → NatureScout) lost its curated claim.
|   2. MaterializeEvergreenOpportunities reused a live opportunity row verbatim.
|      Rows materialized before the pack was published stayed mute until they
|      expired — publishing a pack changed nothing that day.
|
*/

function curatedPlace(string $name, float $lat, float $lng, string $type, string $domain, string $claim): Place
{
    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c;

    $place = Place::factory()->create([
        'name' => $name, 'type' => $type, 'type_domain' => $domain,
        'facets' => ['nature'], 'h3_index' => $cell, 'source_tags' => ['osm' => []],
    ]);

    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [$lng, $lat, $place->id],
    );

    CuratedItem::query()->create([
        'place_id' => $place->id,
        'region_slug' => 'stockholm',
        'title' => $name,
        'claim' => $claim,
        'facets' => ['nature'],
        'evidence' => [['url' => 'https://example.test', 'source_type' => 'wikipedia', 'license' => 'cc-by-sa', 'excerpt' => 'x']],
        'status' => CurationStatus::Approved,
        'authored_by' => 'human',
    ]);

    return $place;
}

function curatedFeed(): array
{
    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 180,
    ]);

    return app(ListOpportunitiesForSession::class)(ExploreSessionData::fromModel($session));
}

it('serves the curated claim for a place another scout also found', function () {
    // A lake is found by NatureScout *before* CuratedScout ever runs. Dedupe
    // must union the claim in, not drop it on the floor.
    curatedPlace('Trekanten', 59.3117, 18.0206, 'lake', 'nature_landscape', 'A lake you can swim in, in the middle of the city.');

    $feed = curatedFeed();

    $item = collect($feed)->firstWhere('title', 'Trekanten');

    expect($item)->not->toBeNull()
        ->and($item->summary)->toBe('A lake you can swim in, in the middle of the city.');
});

it('gives a voice to an opportunity that was materialized before the pack shipped', function () {
    $place = curatedPlace('Trekanten', 59.3117, 18.0206, 'lake', 'nature_landscape', 'A lake you can swim in.');

    // The state after publishing a pack: a live, mute opportunity row from a
    // session that ran yesterday.
    Opportunity::query()->create([
        'place_id' => $place->id,
        'kind' => OpportunityKind::Evergreen,
        'status' => OpportunityStatus::Scored,
        'title' => 'Trekanten',
        'summary' => null,
        'friction' => [],
        'h3_index' => $place->h3_index,
        'expires_at' => now()->addHours(6),
    ]);

    $feed = curatedFeed();

    expect(collect($feed)->firstWhere('title', 'Trekanten')->summary)->toBe('A lake you can swim in.');
});

it('leaves an uncurated place mute rather than inventing a summary', function () {
    curatedPlace('Trekanten', 59.3117, 18.0206, 'lake', 'nature_landscape', 'A lake you can swim in.');

    // No curated item for this one — the LLM is never a source of facts, so the
    // honest thing is silence until a reviewed claim exists.
    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [18.0231, 59.3095])->c;
    $plain = Place::factory()->create([
        'name' => 'Liljeholmstorget', 'type' => 'square', 'type_domain' => 'architecture_urban',
        'facets' => ['local_life'], 'h3_index' => $cell, 'source_tags' => ['osm' => []],
    ]);
    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [18.0231, 59.3095, $plain->id],
    );

    expect(collect(curatedFeed())->firstWhere('title', 'Liljeholmstorget')->summary)->toBeNull();
});
