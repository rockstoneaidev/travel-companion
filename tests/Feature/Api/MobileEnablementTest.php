<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The mobile client's backend (E33 + E36)
|--------------------------------------------------------------------------
|
| The mobile app is a separate repo and a pure /api/v1 consumer — its acceptance test is
| that it needs ZERO backend restructuring (E33). These verify the endpoints it depends on
| that did not exist yet: token login, browse, and the offline geofence bundle.
|
| What these CANNOT verify is the app itself — the screens, the location manager, the
| device-side cache — which need a handset and the Expo toolchain (E34). That is the
| deliberate ceiling; the backend it talks to is real and tested here.
|
*/

it('trades credentials for a bearer token, and rejects wrong ones the same way for everyone', function () {
    User::factory()->create(['email' => 'mats@beet.se', 'password' => bcrypt('correct-horse')]);

    // The happy path: email + password + a device name → a token.
    $this->postJson('/api/v1/auth/token', [
        'email' => 'mats@beet.se', 'password' => 'correct-horse', 'device_name' => "Mats's iPhone",
    ])->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);

    // Wrong password and unknown user fail IDENTICALLY — never a username oracle.
    $wrongPass = $this->postJson('/api/v1/auth/token', [
        'email' => 'mats@beet.se', 'password' => 'wrong', 'device_name' => 'x',
    ]);
    $noUser = $this->postJson('/api/v1/auth/token', [
        'email' => 'nobody@nowhere.se', 'password' => 'wrong', 'device_name' => 'x',
    ]);

    $wrongPass->assertStatus(422);
    $noUser->assertStatus(422);
    expect($wrongPass->json('errors.email'))->toBe($noUser->json('errors.email'));
});

it('serves the whole browsable candidate set to the mobile client', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 180,
    ]);

    for ($i = 0; $i < 12; $i++) {
        $p = Place::factory()->create([
            'h3_index' => DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [18.0227 + $i * 0.001, 59.3103])->c,
            'source_tags' => ['osm' => [], 'wikidata' => []],
        ]);
        DB::statement('UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
            [18.0227 + $i * 0.001, 59.3103, $p->id]);
    }

    Sanctum::actingAs($user);

    $response = $this->getJson("/api/v1/explore-sessions/{$session->id}/browse")->assertOk();

    expect($response->json('total'))->toBeGreaterThan(5)
        ->and($response->json('items.0'))->toHaveKeys(['place_id', 'name', 'score', 'travel_minutes']);
});

it('builds an offline geofence bundle the device enforces itself', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create([
        'user_id' => $user->id,
        'anchor_point' => DB::raw("ST_GeogFromText('POINT(18.07 59.32)')"),
    ]);

    // A live opportunity near the trip anchor.
    $place = Place::factory()->create([
        'h3_index' => DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(18.07, 59.32), 8)::text AS c')->c,
    ]);
    DB::statement("UPDATE places_core SET location = ST_GeogFromText('POINT(18.07 59.32)') WHERE id = ?", [$place->id]);

    DB::table('opportunities')->insert([
        'id' => (string) Str::uuid7(),
        'place_id' => $place->id,
        'kind' => 'evergreen', 'status' => 'scored',
        'title' => 'Katarina kyrka', 'summary' => 'Worth the climb.',
        'h3_index' => $place->h3_index,
        'expires_at' => now()->addDay(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson("/api/v1/trips/{$trip->id}/corridor-payload")->assertOk();

    // A geofence the device can fire on: a circle, the words, and a deep link.
    expect($response->json('geofences'))->toHaveCount(1)
        ->and($response->json('geofences.0'))->toHaveKeys(['lat', 'lng', 'radius_m', 'title', 'body', 'deep_link']);

    /*
     * ...and the BUDGET, which is the whole reason this is safe. The device enforces the
     * same 3-a-day / cooldown / quiet-hours that NotificationPolicy does, offline, as pure
     * arithmetic — one policy, not two that can drift.
     */
    expect($response->json('budget'))->toMatchArray([
        'max_per_day' => 3,
        'cooldown_minutes' => 60,
    ]);
});

it('will not hand one traveller another’s corridor', function () {
    $owner = User::factory()->create();
    $trip = Trip::factory()->create(['user_id' => $owner->id, 'anchor_point' => DB::raw("ST_GeogFromText('POINT(18.07 59.32)')")]);

    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/v1/trips/{$trip->id}/corridor-payload")->assertForbidden();
});
