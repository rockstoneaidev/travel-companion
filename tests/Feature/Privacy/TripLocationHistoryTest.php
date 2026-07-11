<?php

declare(strict_types=1);

use App\Domain\Context\Models\ContextEvent;
use App\Domain\Places\Data\Coordinates;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Trip-level location deletion (PRD §16) — non-negotiably tested (conventions/11)
|--------------------------------------------------------------------------
*/

it('erases every raw coordinate a trip holds, immediately', function () {
    Sanctum::actingAs($user = User::factory()->create());

    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->create([
        'trip_id' => $trip->id,
        'user_id' => $user->id,
        'destination_point' => new Coordinates(59.33, 18.07),
        'origin_h3_index' => '881f1d4887fffff',
    ]);
    ContextEvent::factory()->count(3)->create([
        'explore_session_id' => $session->id,
        'trip_id' => $trip->id,
        'user_id' => $user->id,
        'h3_index' => '881f1d4887fffff',
    ]);

    $response = $this->deleteJson("/api/v1/trips/{$trip->id}/location-history")->assertOk();

    expect($response->json('data.context_events_erased'))->toBe(3);
    expect($response->json('data.trip_rows_erased'))->toBe(2);   // the trip anchor + one session

    expect($trip->fresh()->anchor_point)->toBeNull();

    $session->refresh();
    expect($session->origin)->toBeNull()
        ->and($session->destination_point)->toBeNull()
        ->and($session->origin_h3_index)->toBeNull();

    // The rows survive; the location — raw AND derived — does not.
    expect(ContextEvent::query()->count())->toBe(3);
    expect(ContextEvent::query()->whereNotNull('location')->count())->toBe(0);
    expect(ContextEvent::query()->whereNotNull('h3_index')->count())->toBe(0);
    expect(ContextEvent::query()->whereNotNull('accuracy_meters')->count())->toBe(0);
    expect(ContextEvent::query()->whereNotNull('movement_mode')->count())->toBe(3);   // not location history
});

it('does not touch another trip\'s locations', function () {
    Sanctum::actingAs($user = User::factory()->create());

    $target = Trip::factory()->create(['user_id' => $user->id]);
    $other = Trip::factory()->completed()->create(['user_id' => $user->id]);
    $otherSession = ExploreSession::factory()->create(['trip_id' => $other->id, 'user_id' => $user->id]);

    $this->deleteJson("/api/v1/trips/{$target->id}/location-history")->assertOk();

    expect($other->fresh()->anchor_point)->not->toBeNull();
    expect($otherSession->fresh()->origin)->not->toBeNull();
});

it('cascades: deleting a trip deletes its sessions and context events', function () {
    $user = User::factory()->create();

    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->create(['trip_id' => $trip->id, 'user_id' => $user->id]);
    ContextEvent::factory()->create([
        'explore_session_id' => $session->id,
        'trip_id' => $trip->id,
        'user_id' => $user->id,
    ]);

    $trip->delete();

    expect(ExploreSession::query()->count())->toBe(0);
    expect(ContextEvent::query()->count())->toBe(0);
});
