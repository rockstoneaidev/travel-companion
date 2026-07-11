<?php

declare(strict_types=1);

use App\Domain\Trips\Enums\TripSource;
use App\Domain\Trips\Enums\TripStatus;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lists only the caller\'s trips, paginated', function () {
    Sanctum::actingAs($user = User::factory()->create());

    Trip::factory()->count(3)->completed()->create(['user_id' => $user->id]);
    Trip::factory()->completed()->create();   // someone else's

    $response = $this->getJson('/api/v1/trips?per_page=2')->assertOk();

    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('meta.total'))->toBe(3);
    expect($response->json('meta.per_page'))->toBe(2);
    expect($response->json('meta.filters.sort_by'))->toBe('last_session_at');
});

it('caps per_page at 100 and rejects an unknown sort field', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/trips?per_page=100000')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    $this->getJson('/api/v1/trips?sort_by=user_id')->assertUnprocessable()->assertJsonValidationErrors('sort_by');
});

it('filters trips by status', function () {
    Sanctum::actingAs($user = User::factory()->create());

    Trip::factory()->create(['user_id' => $user->id]);                 // active
    Trip::factory()->completed()->create(['user_id' => $user->id]);

    $response = $this->getJson('/api/v1/trips?status[]=completed')->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.status'))->toBe('completed');
});

it('lets a planner pre-create a named trip, as planned and never active', function () {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->postJson('/api/v1/trips', [
        'name' => 'Burgundy, August',
        'anchor_point' => ['lat' => 47.0240, 'lng' => 4.8390],
    ])->assertCreated();

    expect($response->json('data.name'))->toBe('Burgundy, August');
    expect($response->json('data.status'))->toBe('planned');
    expect($response->json('data.source'))->toBe('user');

    $trip = Trip::query()->sole();

    expect($trip->status)->toBe(TripStatus::Planned)->and($trip->source)->toBe(TripSource::User);
});

it('renames a trip and marks it ended', function () {
    Sanctum::actingAs($user = User::factory()->create());

    $trip = Trip::factory()->create(['user_id' => $user->id]);

    $this->patchJson("/api/v1/trips/{$trip->id}", ['name' => 'Stockholm test'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Stockholm test')
        ->assertJsonPath('data.status', 'active');

    $this->patchJson("/api/v1/trips/{$trip->id}", ['status' => 'completed'])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    expect($trip->fresh()->ended_at)->not->toBeNull();
});

it('refuses to move a trip back to active', function () {
    Sanctum::actingAs($user = User::factory()->create());

    $trip = Trip::factory()->completed()->create(['user_id' => $user->id]);

    $this->patchJson("/api/v1/trips/{$trip->id}", ['status' => 'active'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('shows a trip with its session count and never its anchor point', function () {
    Sanctum::actingAs($user = User::factory()->create());

    $trip = Trip::factory()->create(['user_id' => $user->id]);
    ExploreSession::factory()->count(2)->create(['trip_id' => $trip->id, 'user_id' => $user->id]);

    $response = $this->getJson("/api/v1/trips/{$trip->id}")->assertOk();

    expect($response->json('data.explore_sessions_count'))->toBe(2);
    expect($response->json('data'))->not->toHaveKey('anchor_point');   // raw location never leaves (conventions/06)
    expect($response->json('data'))->not->toHaveKey('clustering_version');
});

it('does not let a user read or change another user\'s trip', function () {
    $trip = Trip::factory()->create();

    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/v1/trips/{$trip->id}")->assertForbidden();
    $this->patchJson("/api/v1/trips/{$trip->id}", ['name' => 'mine now'])->assertForbidden();
    $this->deleteJson("/api/v1/trips/{$trip->id}/location-history")->assertForbidden();
});
