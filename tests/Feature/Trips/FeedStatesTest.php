<?php

declare(strict_types=1);

use App\Domain\Feedback\Models\RecommendationFeedback;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Opportunities\Queries\ListOpportunitiesForSession;
use App\Domain\Places\Models\Place;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E9 — the per-screen state checklist (SCREENS S1, S4, S5)
|--------------------------------------------------------------------------
|
| Only the zero-items state was covered. A feed with items in it, the GO NOW
| slot, the detail screen and the feedback wiring were all untested — which is
| how "urgency is never passed to the card" survived a whole epic.
|
*/

function statePlace(string $name, float $lat, float $lng, string $type, string $domain): Place
{
    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c;

    $place = Place::factory()->create([
        'name' => $name, 'type' => $type, 'type_domain' => $domain,
        'facets' => ['scenic'], 'h3_index' => $cell, 'source_tags' => ['osm' => []],
    ]);

    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [$lng, $lat, $place->id],
    );

    return $place;
}

function openSession(User $user): ExploreSession
{
    $trip = Trip::factory()->create(['user_id' => $user->id]);

    return ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 180,
    ]);
}

beforeEach(function () {
    /*
     * PIN THE CLOCK. The feed is a function of the sun now (E16): a lake is a
     * daylight place, so it acquires a real closing window at sunset and the
     * special-moment floor while the golden hour is open. Without a fixed instant
     * these assertions pass at noon and fail at dusk — which is exactly what CI
     * caught, on a run that happened to land in the evening.
     *
     * Midday in July: hours of light left, no window closing, nothing urgent.
     */
    $this->travelTo(CarbonImmutable::parse('2026-07-13 11:00:00', 'Europe/Stockholm'));

    statePlace('Trekanten', 59.3117, 18.0206, 'lake', 'nature_landscape');
    statePlace('Färgfabriken', 59.3120, 18.0190, 'gallery', 'arts_culture');
    statePlace('Liljeholmstorget', 59.3095, 18.0231, 'square', 'architecture_urban');
});

it('renders a populated feed in server order, with the payload the cards need', function () {
    $user = User::factory()->create();
    $session = openSession($user);

    $props = $this->actingAs($user)->get("/explore/{$session->id}")->viewData('page')['props'];
    $items = $props['opportunities']['data'];

    expect($items)->not->toBeEmpty();

    foreach ($items as $item) {
        // Everything OpportunityCard reads. A missing key here is a blank card.
        expect($item)->toHaveKeys(['id', 'title', 'summary', 'urgent', 'time_window', 'travel_minutes', 'recommendation_id', 'place']);
    }
});

it('marks no card urgent at midday, when nothing is closing', function () {
    $user = User::factory()->create();
    $session = openSession($user);

    $items = $this->actingAs($user)->get("/explore/{$session->id}")->viewData('page')['props']['opportunities']['data'];

    // Nothing shouts without a reason. At 11:00 the lake has a window — sunset — but
    // it is nine hours away, and nine hours away is not urgent. Manufacturing urgency
    // is the one thing this product must never do.
    expect(array_column($items, 'urgent'))->each->toBeFalse();
});

it('is a function of the sun — the same feed goes urgent as the light runs out', function () {
    $user = User::factory()->create();

    // The lake at 21:15, minutes before a Stockholm July sunset. Same place, same
    // walk; what changed is the sky. This is E16's whole claim, and it is the reason
    // the clock above has to be pinned rather than left to whenever CI happens to run.
    $this->travelTo(CarbonImmutable::parse('2026-07-13 21:15:00', 'Europe/Stockholm'));

    $session = openSession($user);
    $items = $this->actingAs($user)->get("/explore/{$session->id}")->viewData('page')['props']['opportunities']['data'];

    $urgent = array_values(array_filter($items, static fn (array $i): bool => $i['urgent']));

    expect($urgent)->not->toBeEmpty()
        ->and($urgent[0]['place']['name'])->toBe('Trekanten');   // the daylight place, not the gallery
});

it('puts the one closing item in the GO NOW slot, at the top', function () {
    $user = User::factory()->create();
    $session = openSession($user);

    // Serve the feed, then give the LAST item a window closing in 40 minutes.
    $this->actingAs($user)->get("/explore/{$session->id}");

    $last = Recommendation::query()
        ->where('explore_session_id', $session->id)
        ->orderByDesc('position')
        ->firstOrFail();

    Opportunity::query()->whereKey($last->opportunity_id)->update([
        'kind' => 'event',
        'window_starts_at' => now()->subHour(),
        'window_ends_at' => now()->addMinutes(40),
    ]);

    $items = $this->actingAs($user)->get("/explore/{$session->id}")->viewData('page')['props']['opportunities']['data'];

    // Promoted to the top, and the only urgent card in the feed.
    expect($items[0]['urgent'])->toBeTrue()
        ->and($items[0]['time_window']['ends_at'])->not->toBeNull()
        ->and(count(array_filter(array_column($items, 'urgent'))))->toBe(1);
});

it('opens the detail screen for a served item', function () {
    $user = User::factory()->create();
    $session = openSession($user);

    $items = $this->actingAs($user)->get("/explore/{$session->id}")->viewData('page')['props']['opportunities']['data'];

    $this->actingAs($user)
        ->get("/opportunities/{$items[0]['id']}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('opportunities/show'));
});

it('records "Take me" with the navigation flag the visit prompt depends on', function () {
    $user = User::factory()->create();
    $session = openSession($user);

    $items = $this->actingAs($user)->get("/explore/{$session->id}")->viewData('page')['props']['opportunities']['data'];
    $recommendationId = $items[0]['recommendation_id'];

    $this->actingAs($user)->post("/recommendations/{$recommendationId}/feedback", [
        'event' => 'accepted',
        'metadata' => ['started_navigation' => true],
    ])->assertRedirect();

    $feedback = RecommendationFeedback::query()->where('recommendation_id', $recommendationId)->sole();

    // Without started_navigation, "Were you there?" can never fire (SCREENS S4).
    expect($feedback->event->value)->toBe('accepted')
        ->and($feedback->metadata['started_navigation'])->toBeTrue();
});

it('shows the empty state rather than an empty list when nothing is reachable', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);

    // Middle of the Baltic: real coordinates, no places.
    $session = ExploreSession::factory()->at(58.0000, 20.0000)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 45,
    ]);

    $this->actingAs($user)
        ->get("/explore/{$session->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('opportunities.data', 0));

    expect(app(ListOpportunitiesForSession::class)(
        ExploreSessionData::fromModel($session),
    ))->toBeEmpty();
});
