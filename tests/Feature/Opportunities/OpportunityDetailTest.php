<?php

declare(strict_types=1);

use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Places\Data\Coordinates;
use App\Domain\Places\Models\Place;
use App\Domain\Recommendations\Models\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| S4 — opportunity detail (SCREENS.md)
|--------------------------------------------------------------------------
|
| The page used to demand a `recommendations` row for (this opportunity, this user)
| and 404 without one. The home screen is built almost entirely from things that have
| no such row — the digest draws the hero, the "Also worth knowing" rows and the dimmed
| pins from the funnel's near-misses and held-back candidates, which by definition were
| WEIGHED AND NOT SERVED. So every clickable thing on the home screen 404'd, the big
| hero photograph included.
|
| A recommendation is optional here. Its absence is a state with a meaning — "I looked
| at this and passed it over" — and the page says so.
|
*/

/** A place, and an opportunity at it. Nothing served, nobody recommended anything. */
function passedOverOpportunity(string $name = 'Sergelfontänen'): Opportunity
{
    $place = Place::factory()->create([
        'name' => $name,
        'location' => new Coordinates(59.3326, 18.0649),
    ]);

    return Opportunity::factory()->create([
        'place_id' => $place->id,
        'status' => OpportunityStatus::Scored,
        'title' => $name,
        'summary' => 'A fountain that Stockholm has never quite agreed about.',
        'expires_at' => now()->addDay(),
    ]);
}

it('opens something the ranker weighed and passed over, instead of 404ing on it', function () {
    $this->actingAs(profilingAsked(User::factory()->create()));

    $opportunity = passedOverOpportunity();

    // This is the exact link the dashboard hero carries, and it used to be a dead one.
    $this->get("/opportunities/{$opportunity->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('opportunities/show')
            ->where('opportunity.title', 'Sergelfontänen')
            // The place is sourced from places_core, which is there whether or not a
            // recommendation ever froze a copy of it into a trace.
            ->where('place.name', 'Sergelfontänen')
            ->where('place.lat', fn (float $lat): bool => abs($lat - 59.3326) < 0.0001)
            // Nothing was served, so there is no trace to explain and no served item for
            // an opinion to attach itself to. Both are null, and the page copes.
            ->where('recommendation', null)
            ->where('explanation', null)
            ->where('sessionId', null));
});

it('still shows the trace when there IS one — the explanation is not what got dropped', function () {
    $this->actingAs($user = profilingConsent(User::factory()->create()));

    $opportunity = passedOverOpportunity('Färgfabriken');

    Recommendation::query()->create([
        'user_id' => $user->id,
        'opportunity_id' => $opportunity->id,
        'position' => 1,
        'scores' => [],
        'score_inputs' => [
            'candidate' => ['name' => 'Färgfabriken', 'lat' => 59.31, 'lng' => 18.02, 'facets' => ['history']],
            'reachability' => ['travel_min' => 12],
            'raw' => ['personal_fit' => ['history' => 0.9]],
        ],
        'scoring_model_version' => 'v1',
        'taxonomy_version' => 1,
        'served_at' => now(),
    ]);

    $this->get("/opportunities/{$opportunity->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('recommendation.id')
            ->where('recommendation.walk_minutes', 12)
            ->has('explanation.evidence'));
});

it('404s on an opportunity that does not exist at all — the id is still a claim', function () {
    $this->actingAs(profilingAsked(User::factory()->create()));

    // Dropping the recommendation requirement must not turn the route into one that
    // accepts anything: a made-up id is still a made-up id.
    $this->get('/opportunities/019f5672-0000-7000-8000-000000000000')->assertNotFound();
});
