<?php

declare(strict_types=1);

use App\Domain\Context\Enums\ContextSource;
use App\Domain\Context\Models\ContextEvent;
use App\Domain\Trips\Models\Device;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E29 — Trip Mode: the switch that lets the app follow you
|--------------------------------------------------------------------------
|
| Everything Phase 2 is allowed to do — background location, geofences, interrupting
| somebody who is not looking at their phone — is downstream of this. PRD §16: "No
| passive companionship unless the user turns it on." PRD risk 4: getting background
| behaviour wrong kills trust, battery, or app review, and "will not be treated casually".
|
| So the rules are ENFORCED on the server, not trusted to a mobile client. A rule that
| lives only in the app is a rule that lasts until the next release.
|
*/

const HOME = ['lat' => 59.3103, 'lng' => 18.0227];
const AWAY = ['lat' => 59.3400, 'lng' => 18.0700];   // ~4 km from home

function tripFor(User $user): Trip
{
    return Trip::factory()->create(['user_id' => $user->id]);
}

function ping(Trip $trip, array $at, array $overrides = []): TestResponse
{
    return test()->postJson("/api/v1/trips/{$trip->id}/context-events", [
        'location' => ['lat' => $at['lat'], 'lng' => $at['lng'], 'accuracy_m' => 20],
        'power_tier' => 'low',
        'app_state' => 'background',
        ...$overrides,
    ]);
}

it('does not follow anybody until they say so', function () {
    Sanctum::actingAs($user = User::factory()->create());
    $trip = tripFor($user);

    expect($trip->inTripMode())->toBeFalse();

    /*
     * A background ping for a trip whose mode is off is not stored, not coarsened, not
     * counted — it is REFUSED. The client should not have sent it; the server does not
     * care whether it meant to. "No passive companionship unless the user turns it on"
     * (PRD §16) is not a UI state, it is a server rule.
     */
    ping($trip, AWAY)
        ->assertAccepted()
        ->assertJsonPath('recorded', false)
        ->assertJsonPath('reason', 'trip_mode_off');

    expect(ContextEvent::query()->count())->toBe(0);
});

it('follows once they do, and stops the moment they change their mind', function () {
    Sanctum::actingAs($user = User::factory()->create());
    $trip = tripFor($user);

    $this->postJson("/api/v1/trips/{$trip->id}/trip-mode/start")
        ->assertOk()
        ->assertJsonPath('data.trip_mode', true)
        // "Since when" is the question a privacy screen has to answer, so the timestamp is
        // exposed and not merely the boolean.
        ->assertJsonPath('data.trip_mode_started_at', fn ($at): bool => $at !== null);

    ping($trip, AWAY)->assertAccepted()->assertJsonPath('recorded', true);

    expect(ContextEvent::query()->count())->toBe(1)
        // The load-bearing schema change: a background event has NO explore session. Its
        // parent is the trip. Inventing a session to hang it off would be a lie in a table.
        ->and(ContextEvent::query()->first()->explore_session_id)->toBeNull()
        ->and(ContextEvent::query()->first()->power_tier->value)->toBe('low');

    $this->postJson("/api/v1/trips/{$trip->id}/trip-mode/stop")
        ->assertOk()
        ->assertJsonPath('data.trip_mode', false);

    ping($trip, ['lat' => 60.0, 'lng' => 19.0])
        ->assertJsonPath('recorded', false)
        ->assertJsonPath('reason', 'trip_mode_off');

    expect(ContextEvent::query()->count())->toBe(1);
});

it('lets the off-switch work from any state at all', function () {
    Sanctum::actingAs($user = User::factory()->create());
    $trip = tripFor($user);

    /*
     * Never on, already off, trip already ended, a client retrying because it did not hear
     * us the first time. An off-switch that can fail is an off-switch nobody trusts, and it
     * is the single control the whole consent story rests on.
     */
    $this->postJson("/api/v1/trips/{$trip->id}/trip-mode/stop")->assertOk();
    $this->postJson("/api/v1/trips/{$trip->id}/trip-mode/stop")->assertOk();

    $trip->forceFill(['status' => 'completed'])->save();

    $this->postJson("/api/v1/trips/{$trip->id}/trip-mode/stop")->assertOk();
});

it('refuses to start Trip Mode on a trip that is over', function () {
    Sanctum::actingAs($user = User::factory()->create());

    $trip = Trip::factory()->completed()->create(['user_id' => $user->id]);

    // The mode follows a journey in progress, and there is none.
    $this->postJson("/api/v1/trips/{$trip->id}/trip-mode/start")->assertStatus(409);

    expect($trip->fresh()->inTripMode())->toBeFalse();
});

it('never tracks anybody at home — not even the coarse cell', function () {
    Sanctum::actingAs($user = User::factory()->create());

    DB::statement(
        'UPDATE users SET home_zone_center = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, home_zone_radius_meters = 300 WHERE id = ?',
        [HOME['lng'], HOME['lat'], $user->id],
    );

    $trip = tripFor($user);
    $this->postJson("/api/v1/trips/{$trip->id}/trip-mode/start")->assertOk();

    ping($trip, HOME)
        ->assertAccepted()
        ->assertJsonPath('recorded', false)
        ->assertJsonPath('reason', 'home_zone');

    /*
     * NOT "recorded without a coordinate". NOT recorded.
     *
     * The session path keeps the coarse H3 cell inside the home zone and drops only the
     * coordinate — defensible, because the user is looking at a screen and asked for
     * something. Background is stricter, because NOBODY ASKED. A trail of coarse cells at a
     * person's home address, gathered while they were not using the app, is precisely what
     * this product promises never to hold (PRD §13.4: "No tracking: at home").
     */
    expect(ContextEvent::query()->count())->toBe(0);

    // ...and away from home the same phone is recorded normally.
    ping($trip, AWAY)->assertJsonPath('recorded', true);

    expect(ContextEvent::query()->count())->toBe(1);
});

it('refuses to become a raw GPS stream', function () {
    Sanctum::actingAs($user = User::factory()->create());
    $trip = tripFor($user);
    $this->postJson("/api/v1/trips/{$trip->id}/trip-mode/start")->assertOk();

    ping($trip, AWAY)->assertJsonPath('recorded', true);

    /*
     * PRD §13.4, in italics: the phone sends MEANINGFUL CONTEXT CHANGES — "Never 'GPS every
     * 5 seconds → backend reasons → push'."
     *
     * That is a promise about battery and about trust, and it is exactly the kind of promise
     * that erodes quietly: a client bug, a retry loop, an over-eager summarizer — and
     * suddenly we are holding a second-by-second track of somebody's day. So the floor is
     * enforced HERE, where the mobile team cannot regress it.
     */
    for ($i = 1; $i <= 5; $i++) {
        ping($trip, ['lat' => AWAY['lat'] + $i * 0.0002, 'lng' => AWAY['lng']])   // ~22 m apart
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('reason', 'not_meaningful');
    }

    expect(ContextEvent::query()->count())->toBe(1);

    // A real move, though, is a real event.
    ping($trip, ['lat' => AWAY['lat'] + 0.005, 'lng' => AWAY['lng']])   // ~550 m
        ->assertJsonPath('recorded', true);

    expect(ContextEvent::query()->count())->toBe(2);
});

it('keeps the traveller who sat still in a café for an hour', function () {
    Sanctum::actingAs($user = User::factory()->create());
    $trip = tripFor($user);
    $this->postJson("/api/v1/trips/{$trip->id}/trip-mode/start")->assertOk();

    ping($trip, AWAY)->assertJsonPath('recorded', true);

    /*
     * Far enough OR long enough — never both. Requiring both would discard exactly the
     * moment the companion exists for: somebody who has not moved a metre in an hour, and
     * for whom the light has changed, the market has opened, the museum is about to close.
     */
    $this->travelTo(now()->addHour());

    ping($trip, AWAY)->assertJsonPath('recorded', true);

    expect(ContextEvent::query()->count())->toBe(2);
});

it('will not let one traveller drive another traveller’s trip', function () {
    $other = User::factory()->create();
    $theirTrip = tripFor($other);

    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/trips/{$theirTrip->id}/trip-mode/start")->assertForbidden();
    ping($theirTrip, AWAY)->assertForbidden();
});

it('registers a phone once, however many times its token is reissued', function () {
    Sanctum::actingAs($user = User::factory()->create());

    $this->postJson('/api/v1/devices', [
        'platform' => 'ios', 'push_token' => 'tok-abcdef123456', 'app_version' => '1.0.0',
    ])->assertCreated()->assertJsonPath('data.platform', 'ios')
        // The token is never echoed back: it is a credential AND an identifier, and a
        // payload that repeats it is a payload that ends up in a log.
        ->assertJsonMissingPath('data.push_token');

    /*
     * FCM and APNs reissue tokens — on reinstall, on OS upgrade, on their own schedule —
     * and the same handset will present the same token again. Inserting blindly gives one
     * person four rows, and four rows is the same notification four times, which is exactly
     * the fatigue PRD §12 exists to prevent.
     */
    $this->postJson('/api/v1/devices', [
        'platform' => 'ios', 'push_token' => 'tok-abcdef123456', 'app_version' => '1.1.0',
    ])->assertCreated();

    expect(Device::query()->count())->toBe(1)
        ->and(Device::query()->sole()->app_version)->toBe('1.1.0');
});

it('silences a phone without forgetting it existed', function () {
    Sanctum::actingAs($user = User::factory()->create());

    $this->postJson('/api/v1/devices', ['platform' => 'android', 'push_token' => 'tok-zzzzzzzzzzz']);
    $device = Device::query()->sole();

    $this->deleteJson("/api/v1/devices/{$device->id}")->assertNoContent();

    // Revoked, not deleted. A deleted row cannot explain a silence, and "we stopped being
    // able to reach this person on the 3rd" is worth having when they ask why nothing
    // arrived on the 4th.
    expect(Device::query()->count())->toBe(1)
        ->and($device->fresh()->isLive())->toBeFalse();
});

it('carries provenance into the background stream, so an emulated trip teaches nothing', function () {
    Sanctum::actingAs($user = User::factory()->create());

    // A trip born of an emulated session (E47) — an operator driving a pin, not a traveller.
    $trip = Trip::factory()->create(['user_id' => $user->id, 'context_source' => ContextSource::Emulated]);

    $this->postJson("/api/v1/trips/{$trip->id}/trip-mode/start")->assertOk();
    ping($trip, AWAY)->assertJsonPath('recorded', true);

    /*
     * Provenance flows DOWN — the trip is the root for the background stream exactly as the
     * session is for the foreground one (ADMIN §6). Without this, Trip Mode would be a
     * brand-new door into the pipeline with no flag on it, and every invariant E47 built
     * would have a hole in the side.
     */
    expect(ContextEvent::query()->sole()->context_source)->toBe(ContextSource::Emulated);
});
