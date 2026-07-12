<?php

declare(strict_types=1);

use App\Domain\Trips\Models\ExploreSession;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| S3 — MAP (SCREENS.md)
|--------------------------------------------------------------------------
|
| The map is a second VIEW of the feed, not a second ranking. These tests hold
| that line: same session, same domain query, same server-decided urgency.
|
*/

it('draws the session on the map with its origin', function () {
    $this->actingAs($user = User::factory()->create());

    $this->post('/explore', [
        'origin' => stockholmOrigin(),
        'time_budget_minutes' => 180,
        'travel_mode' => 'walk',
    ]);

    $session = ExploreSession::query()->sole();

    $this->get("/explore/{$session->id}/map")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('explore/map')
            ->where('session.data.id', $session->id)
            // The map cannot draw "you" without it, and it is the user's own declared
            // input — not observed location history (ExploreSessionResource).
            ->has('session.data.origin.lat')
            ->has('session.data.origin.lng')
            ->has('opportunities.data'));
});

it('sends a bare /map to the session you already have', function () {
    $this->actingAs(User::factory()->create());

    $this->post('/explore', [
        'origin' => stockholmOrigin(),
        'time_budget_minutes' => 180,
        'travel_mode' => 'walk',
    ]);

    $session = ExploreSession::query()->sole();

    $this->get('/map')->assertRedirect("/explore/{$session->id}/map");
});

it('sends a bare /map to the start form when there is no session', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/map')->assertRedirect('/explore');
});

it('will not show one traveller another traveller\'s map', function () {
    $this->actingAs(User::factory()->create());

    $this->post('/explore', [
        'origin' => stockholmOrigin(),
        'time_budget_minutes' => 180,
        'travel_mode' => 'walk',
    ]);

    $session = ExploreSession::query()->sole();

    // Ownership gates the map exactly as it gates the feed — the geography of where
    // someone is walking today is not less private than the list of it.
    $this->actingAs(User::factory()->create());

    $this->get("/explore/{$session->id}/map")->assertForbidden();
});
