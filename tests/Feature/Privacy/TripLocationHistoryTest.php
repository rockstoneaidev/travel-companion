<?php

declare(strict_types=1);

use App\Domain\Context\Models\ContextEvent;
use App\Domain\Places\Data\Coordinates;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

it('erases the serve anchors on the trip’s recommendation traces, but keeps the traces', function () {
    Sanctum::actingAs($user = User::factory()->create());

    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->create(['trip_id' => $trip->id, 'user_id' => $user->id]);

    DB::table('recommendations')->insert([
        'id' => DB::raw('gen_random_uuid()'),
        'user_id' => $user->id,
        'explore_session_id' => $session->id,
        'trip_id' => $trip->id,
        'opportunity_id' => null,
        'position' => 1,
        'serve_group' => 1,
        'serve_reason' => 'initial',
        'anchor' => DB::raw("ST_GeogFromText('SRID=4326;POINT(18.0227 59.3103)')"),
        'anchor_h3_index' => '881f1d4887fffff',
        'scores' => json_encode([]),
        'score_inputs' => json_encode(['candidate' => ['name' => 'Vinterviken', 'lat' => 59.3117, 'lng' => 18.0206]]),
        'scoring_model_version' => 'v1',
        'taxonomy_version' => 1,
        'served_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->deleteJson("/api/v1/trips/{$trip->id}/location-history")->assertOk();

    expect($response->json('data.traces_erased'))->toBe(1);

    $row = DB::table('recommendations')->first();
    $candidate = json_decode($row->score_inputs, true)['candidate'];

    /*
     * `DeleteTripLocationHistory` used to say recommendation traces "carry no
     * coordinate columns yet" and defer a RecommendationTraceEraser to E17. That was
     * true when it was written and stopped being true the moment the living feed added
     * `recommendations.anchor` to record where each batch was ranked from — a precise
     * coordinate, on a trip-scoped table, that "delete my location history" would have
     * walked straight past.
     *
     * Unlike the 30-day coarsening, this erases the H3 cell too: on-demand deletion
     * removes raw AND derived location data (PRD §16). Same columns, opposite intent.
     */
    expect($row->anchor)->toBeNull()
        ->and($row->anchor_h3_index)->toBeNull()
        ->and($candidate)->not->toHaveKey('lat')
        ->and($candidate)->not->toHaveKey('lng')
        // The DECISION survives — erasing where you stood is not erasing what we told you.
        ->and($candidate['name'])->toBe('Vinterviken')
        ->and($row->serve_reason)->toBe('initial');
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
