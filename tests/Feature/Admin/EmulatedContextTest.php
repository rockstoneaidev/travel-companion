<?php

declare(strict_types=1);

use App\Domain\Context\Enums\ContextSource;
use App\Domain\Context\Models\ContextEvent;
use App\Domain\Places\Models\Place;
use App\Domain\Profiles\Models\UserTasteProfile;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Enums\CostActorKind;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(fn () => test()->seed(RolesAndPermissionsSeeder::class));

/*
|--------------------------------------------------------------------------
| Emulated context is MARKED, and never learned from (ADMIN §6, §14)
|--------------------------------------------------------------------------
|
| ADMIN §14 says this file has to exist, in as many words:
|
|   "Position emulation, once built, must have the test that emulated context never
|    lands in learning signals or cost metrics — that is a CLAUDE.md-grade invariant
|    for this console."
|
| The emulator drives the REAL pipeline from a fabricated position — same ingestion
| boundary, same scouts, same scoring, same feed. That is the entire point of it, and
| exactly why it is dangerous: an operator walking a synthetic path through Stockholm
| produces feedback, spend and traces that are indistinguishable from a real
| traveller's. Without the flag these tests defend, the console we built to WATCH the
| metrics would be the thing corrupting them.
|
*/

function emulatedSession(User $user): ExploreSession
{
    $trip = Trip::factory()->create(['user_id' => $user->id]);

    return ExploreSession::factory()->emulated()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 120,
    ]);
}

function emulatedPlace(string $name): void
{
    $lat = 59.3110;
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

it('marks every hop of the pipeline: session → context event → decision trace', function () {
    emulatedPlace('Vinterviken');

    $this->actingAs($user = profilingConsent(User::factory()->superadmin()->create()));
    $session = emulatedSession($user);

    app(RankSession::class)->feedFor(ExploreSessionData::fromModel($session));

    $this->postJson("/explore/{$session->id}/context-events", [
        'location' => ['lat' => 59.3155, 'lng' => 18.0345, 'accuracy_m' => 20],
        'app_state' => 'foreground',
    ])->assertNoContent();

    // §6: "every context event records context_source", and "the value propagates onto
    // the decision trace of everything downstream".
    expect(ContextEvent::query()->first()->context_source)->toBe(ContextSource::Emulated)
        ->and(Recommendation::query()->first()->context_source)->toBe(ContextSource::Emulated);
});

it('does not let a client claim to be emulated', function () {
    emulatedPlace('Vinterviken');

    $this->actingAs($user = profilingConsent(User::factory()->create()));

    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $user->id,
    ]);

    // A real phone, posting a payload that tries to launder itself as emulated — which
    // would be a free pass out of cost metrics for anyone who found the field.
    $this->postJson("/explore/{$session->id}/context-events", [
        'location' => ['lat' => 59.3110, 'lng' => 18.0227, 'accuracy_m' => 20],
        'app_state' => 'foreground',
        'context_source' => 'emulated',
    ])->assertNoContent();

    // Provenance is a property of the SESSION, and there is no field in the payload to
    // say otherwise. "Is this real?" is not a question the caller answers about itself.
    expect(ContextEvent::query()->first()->context_source)->toBe(ContextSource::Device);
});

it('never moves the taste profile — but still records the tap', function () {
    emulatedPlace('Vinterviken');

    $this->actingAs($user = profilingConsent(User::factory()->superadmin()->create()));
    $session = emulatedSession($user);

    app(RankSession::class)->feedFor(ExploreSessionData::fromModel($session));

    $recommendation = Recommendation::query()->where('explore_session_id', $session->id)->firstOrFail();
    $before = UserTasteProfile::for((int) $user->id)->facet_weights['scenic'] ?? 0.5;

    $this->postJson("/recommendations/{$recommendation->id}/feedback", ['event' => 'dismissed'])
        ->assertNoContent();

    $profile = UserTasteProfile::for((int) $user->id)->fresh();

    /*
     * The weight does not move, and — just as importantly — the event is not COUNTED.
     * `event_counts` warms α out of cold start (SCORING §6), so an operator clicking
     * around the emulator for an afternoon would otherwise graduate their own profile
     * from cold-start weights on the strength of opinions nobody held.
     */
    expect($profile->facet_weights['scenic'] ?? 0.5)->toEqualWithDelta($before, 0.0001)
        ->and($profile->event_counts['dismissed'] ?? 0)->toBe(0);

    /*
     * But the LEDGER is written — deliberately. It is what makes the feed behave:
     * `withoutDismissed()` reads it and E46's dismiss-backfill is driven by it, so an
     * operator must be able to dismiss a card in the emulator and watch a new one slide
     * in. Recording without learning is the whole trick; a filter that dropped the tap
     * entirely would have made the emulator unable to demonstrate the thing it exists
     * to demonstrate.
     */
    expect(DB::table('recommendation_feedback')->where('recommendation_id', $recommendation->id)->count())->toBe(1);
});

it('meters an emulated session as the operator, not as a user', function () {
    emulatedPlace('Vinterviken');

    $this->actingAs($user = profilingConsent(User::factory()->superadmin()->create()));
    $session = emulatedSession($user);

    $this->get("/explore/{$session->id}")->assertOk();

    /*
     * `MeterCost` has read the `context_source` request attribute since the cost epic
     * and CostActorKind::AdminEmulated has existed just as long — but NOTHING EVER SET
     * IT. Until now, every emulated request metered as real user spend, and founder
     * testing is most of the traffic today.
     *
     * The spend is still recorded: the wallet counts everything (ADMIN §2.4). What it
     * is not is a *user's* usage, and `CostExplorer` / `CostRollup` already filter
     * product metrics to actor_kind = user — so the moment the attribute is set, the
     * trip-hour metric stops being poisoned by our own testing.
     */
    $actors = DB::table('cost_events')->where('user_id', $user->id)->pluck('actor_kind')->unique();

    expect($actors)->not->toBeEmpty()
        ->and($actors->all())->toBe([CostActorKind::AdminEmulated->value]);
});

it('never hands the operator their emulator session as their own feed', function () {
    emulatedPlace('Vinterviken');

    $this->actingAs($user = profilingConsent(User::factory()->superadmin()->create()));
    $emulated = emulatedSession($user);

    /*
     * /explore resolves "the session I have open". If it could return the emulator's,
     * an operator with the console open in one tab would find their own app showing a
     * feed for a pin in Hornstull — and every tap they made would be recorded against a
     * position they were never standing in.
     */
    $this->get('/explore')->assertOk()->assertInertia(
        fn ($page) => $page->component('explore/index')   // the START form, not the emulated feed
    );

    // ...and the emulated session is still perfectly reachable by id, from the console.
    $this->get("/explore/{$emulated->id}")->assertOk();
});

it('refuses to freeze an emulated session as a gold trace', function () {
    emulatedPlace('Vinterviken');

    $this->actingAs($user = profilingConsent(User::factory()->superadmin()->create()));
    $session = emulatedSession($user);

    app(RankSession::class)->feedFor(ExploreSessionData::fromModel($session));

    /*
     * A gold trace is a claim about reality (PRD §15.2) — "would this change have
     * altered what we served a real person, on a real afternoon?". A walk nobody took
     * cannot be ground truth for the ranking model.
     */
    $this->artisan("replay:record {$session->id}")
        ->expectsOutputToContain('EMULATED')
        ->assertFailed();
});

it('gates the emulator on a superadmin-only permission', function () {
    // A plain admin holds every other console permission and still may not do this:
    // it drives the real pipeline from a fabricated position (ADMIN §3.2, §6).
    $this->actingAs(profilingAsked(User::factory()->admin()->create()));
    $this->get('/admin/emulator')->assertForbidden();

    $this->actingAs(profilingAsked(User::factory()->superadmin()->create()));
    $this->get('/admin/emulator')->assertOk();
});
