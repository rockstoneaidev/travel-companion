<?php

declare(strict_types=1);

use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Places\Models\Place;
use App\Domain\Profiles\Models\UserTasteProfile;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Recommendations\Queries\ListDismissedForUser;
use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| "Not for me" — and the way back from it (SCREENS S1/S6)
|--------------------------------------------------------------------------
|
| The feed is served once and thereafter REPLAYED from the stored
| recommendations, so for a while a dismissal hid the card client-side, posted
| itself to the ledger, and then came straight back on the next reload. The
| ledger knew; the replay never asked it.
|
| It is the most destructive tap in the product — it hides the card AND teaches
| the profile to serve fewer like it — so it also has to be undoable.
|
*/

function dismissedPlace(string $name, int $metresNorth = 100): void
{
    $lat = 59.3103 + $metresNorth / 111_320;
    $lng = 18.0227;

    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c;

    $place = Place::factory()->create([
        'name' => $name, 'type' => 'viewpoint', 'type_domain' => 'nature_landscape',
        'facets' => ['scenic'], 'h3_index' => $cell, 'source_tags' => ['osm' => []],
    ]);

    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [$lng, $lat, $place->id],
    );
}

/** A session whose feed has been served once — exactly the state a reload replays. */
function dismissedSession(User $user): ExploreSession
{
    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 45,
    ]);

    app(RankSession::class)->feedFor(ExploreSessionData::fromModel($session));

    return $session;
}

it('keeps a dismissed item out of the feed when the page is reloaded', function () {
    dismissedPlace('Skinnarviksberget');
    dismissedPlace('Ivar Los park', 140);

    $this->actingAs($user = profilingConsent(User::factory()->create()));
    $session = dismissedSession($user);

    $served = Recommendation::query()->where('explore_session_id', $session->id)->get();
    expect($served)->toHaveCount(2);

    $rejected = $served->first();
    $this->postJson("/recommendations/{$rejected->id}/feedback", ['event' => 'dismissed'])->assertNoContent();

    // The bug, in one assertion: this is the GET the browser makes on reload.
    $this->get("/explore/{$session->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('explore/show')
            ->has('opportunities.data', 1)
            ->where('opportunities.data.0.recommendation_id', $served->last()->id));
});

it('brings it back when the user says show me these again', function () {
    dismissedPlace('Skinnarviksberget');

    $this->actingAs($user = profilingConsent(User::factory()->create()));
    $session = dismissedSession($user);

    $recommendation = Recommendation::query()->where('explore_session_id', $session->id)->firstOrFail();

    $this->postJson("/recommendations/{$recommendation->id}/feedback", ['event' => 'dismissed']);
    $this->get("/explore/{$session->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->has('opportunities.data', 0));

    $this->postJson("/recommendations/{$recommendation->id}/feedback", ['event' => 'undismissed'])->assertNoContent();

    $this->get("/explore/{$session->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->has('opportunities.data', 1));

    // Retracted, never deleted: the ledger is append-only and it is the moat.
    expect(DB::table('recommendation_feedback')->where('recommendation_id', $recommendation->id)->count())->toBe(2);
});

it('still says Kept after a reload, and stops saying it once removed', function () {
    dismissedPlace('Skinnarviksberget');

    $this->actingAs($user = profilingConsent(User::factory()->create()));
    $session = dismissedSession($user);

    $recommendation = Recommendation::query()->where('explore_session_id', $session->id)->firstOrFail();

    $this->get("/explore/{$session->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('opportunities.data.0.kept', false));

    $this->postJson("/recommendations/{$recommendation->id}/feedback", ['event' => 'saved']);

    // Local React state would have forgotten this — the card has to be told.
    $this->get("/explore/{$session->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('opportunities.data.0.kept', true));

    $this->postJson("/recommendations/{$recommendation->id}/feedback", ['event' => 'unsaved']);

    $this->get("/explore/{$session->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('opportunities.data.0.kept', false));
});

it('lists dismissals on KEPT, newest first, and drops the ones already restored', function () {
    $this->actingAs($user = profilingConsent(User::factory()->create()));

    $stale = dismissedFixture($user, 'Vinterviken');
    $fresh = dismissedFixture($user, 'Tantolunden');
    $undone = dismissedFixture($user, 'Årstaviken');

    $this->travelTo(now()->subHour());
    $this->postJson("/recommendations/{$stale}/feedback", ['event' => 'dismissed']);
    $this->travelBack();

    $this->postJson("/recommendations/{$fresh}/feedback", ['event' => 'dismissed']);
    $this->postJson("/recommendations/{$undone}/feedback", ['event' => 'dismissed']);
    $this->postJson("/recommendations/{$undone}/feedback", ['event' => 'undismissed']);

    $dismissed = app(ListDismissedForUser::class)->forUser((int) $user->id);

    expect($dismissed)->toHaveCount(2)
        ->and($dismissed[0]->title)->toBe('Tantolunden')   // newest dismissal first
        ->and($dismissed[1]->title)->toBe('Vinterviken')
        ->and(array_column($dismissed, 'title'))->not->toContain('Årstaviken');

    $this->get('/kept')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('kept')
            ->has('dismissed.data', 2)
            ->where('dismissed.data.0.title', 'Tantolunden'));
});

it('gives back what the dismissal took from the taste profile', function () {
    $this->actingAs($user = profilingConsent(User::factory()->create()));

    $recommendation = dismissedFixture($user, 'Vinterviken');

    $before = UserTasteProfile::for((int) $user->id)->facet_weights['scenic'] ?? 0.5;

    $this->postJson("/recommendations/{$recommendation}/feedback", ['event' => 'dismissed']);

    $after = UserTasteProfile::for((int) $user->id)->fresh()->facet_weights['scenic'];
    expect($after)->toBeLessThan($before);   // η .25 toward 0 — the tap did what it says

    $this->postJson("/recommendations/{$recommendation}/feedback", ['event' => 'undismissed']);

    $profile = UserTasteProfile::for((int) $user->id)->fresh();

    // Back where it started: "I didn't mean that" is not "I love this", so the
    // retraction inverts the dismissal rather than applying some positive η.
    expect($profile->facet_weights['scenic'])->toEqualWithDelta($before, 0.0001)
        // ...and it must not still be counted as evidence, or it warms them out of
        // cold start on the strength of an opinion they withdrew (SCORING §6).
        ->and($profile->event_counts['dismissed'] ?? 0)->toBe(0);
});

/** A served recommendation for $user, dismissible, with one scenic facet. */
function dismissedFixture(User $user, string $name): string
{
    $opportunity = Opportunity::factory()->create([
        'status' => OpportunityStatus::Served,
        'title' => $name,
        'summary' => 'A view over the water.',
        'expires_at' => now()->addDay(),
    ]);

    return Recommendation::query()->create([
        'user_id' => $user->id,
        'opportunity_id' => $opportunity->id,
        'position' => 1,
        'scores' => [],
        'score_inputs' => ['candidate' => ['name' => $name, 'lat' => 59.31, 'lng' => 18.02, 'facets' => ['scenic']]],
        'scoring_model_version' => 'v1',
        'taxonomy_version' => 1,
        'served_at' => now(),
    ])->id;
}
