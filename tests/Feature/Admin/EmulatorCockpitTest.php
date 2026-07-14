<?php

declare(strict_types=1);

use App\Domain\Context\Enums\ContextSource;
use App\Domain\Context\Models\ContextEvent;
use App\Domain\Places\Models\Place;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(fn () => test()->seed(RolesAndPermissionsSeeder::class));

/*
|--------------------------------------------------------------------------
| The cockpit (ADMIN §6, E47) — the acceptance test from the epic
|--------------------------------------------------------------------------
|
| "An operator plays a walk path Liljeholmen → Hornstull, watches the cone re-aim and
|  scouts fire in the log, and the phone pane shows what that position serves."
|
| Which is really one claim: a pin dragged across a map moves the REAL pipeline. Every
| tick is an ordinary context event through the ordinary ingestion boundary, so if this
| passes, the thing being tested is the product and not a lookalike of it.
|
*/

const EMU_LILJEHOLMEN = ['lat' => 59.3103, 'lng' => 18.0227];
const EMU_HORNSTULL = ['lat' => 59.3155, 'lng' => 18.0345];

function cockpitPlace(string $name, array $at): void
{
    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$at['lng'], $at['lat']])->c;

    $place = Place::factory()->create([
        'name' => $name, 'type' => 'viewpoint', 'type_domain' => 'nature_landscape',
        'facets' => ['scenic'], 'h3_index' => $cell, 'source_tags' => ['osm' => []],
    ]);

    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [$at['lng'], $at['lat'], $place->id],
    );
}

it('drives the real pipeline from a pin: Liljeholmen → Hornstull re-anchors the feed', function () {
    cockpitPlace('Vinterviken', EMU_LILJEHOLMEN);
    cockpitPlace('Tantolunden', EMU_HORNSTULL);

    $this->actingAs($operator = profilingConsent(User::factory()->superadmin()->create()));

    // Drop the pin and start emulating.
    $this->post('/admin/emulator/sessions', [
        'origin' => EMU_LILJEHOLMEN,
        'travel_mode' => 'walk',
        'time_budget_minutes' => 45,
    ])->assertRedirect('/admin/emulator');

    $session = ExploreSession::query()->where('user_id', $operator->id)->firstOrFail();
    expect($session->context_source)->toBe(ContextSource::Emulated);

    // The phone pane: the REAL feed, for the emulated user. Liljeholmen only — Hornstull
    // is out of reach on a 45-minute walking budget from here.
    $this->get("/explore/{$session->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('opportunities.data.0.title', 'Vinterviken'));

    // Play the walk. Each tick is a real context event through the real boundary — there
    // is no emulator-shaped shortcut into the pipeline.
    $this->travelTo(now()->addMinutes(3));

    $this->postJson('/admin/emulator/positions', [
        'session_id' => $session->id,
        'location' => EMU_HORNSTULL,
        'movement' => ['mode' => 'walking', 'speed_mps' => 1.4, 'heading' => 45],
    ])->assertAccepted();

    /*
     * ...and the feed re-anchors, for real (E46). This is the line the epic exists for:
     * no more walking to Hornstull to test a re-anchor.
     */
    $this->get("/explore/{$session->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('opportunities.data.0.title', 'Tantolunden')
            ->where('serve.reason', 'move_reanchor'));
});

it('shows the coverage the pipeline is actually about to scout', function () {
    cockpitPlace('Vinterviken', EMU_LILJEHOLMEN);

    $this->actingAs(profilingConsent(User::factory()->superadmin()->create()));

    $this->post('/admin/emulator/sessions', [
        'origin' => EMU_LILJEHOLMEN, 'travel_mode' => 'walk', 'time_budget_minutes' => 60,
    ]);

    /*
     * `CoverageGeometry` has always known exactly which res-8 hexagons it was about to
     * scout, and until now nothing could DRAW them: every H3 caller in the codebase
     * wanted a centroid, so nobody had ever asked for a boundary. These polygons are
     * that missing half-line of SQL — the cone, made visible.
     */
    $this->get('/admin/emulator')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/emulator')
            ->has('coverage.cells')
            ->where('coverage.mode', 'walk')
            ->has('coverage.cells.0.geometry.coordinates')
            ->has('log'));
});

it('answers "what would this serve?" without serving it', function () {
    cockpitPlace('Vinterviken', EMU_LILJEHOLMEN);

    $this->actingAs($operator = profilingConsent(User::factory()->superadmin()->create()));

    $this->post('/admin/emulator/sessions', [
        'origin' => EMU_LILJEHOLMEN, 'travel_mode' => 'walk', 'time_budget_minutes' => 60,
    ]);

    $session = ExploreSession::query()->where('user_id', $operator->id)->firstOrFail();
    $before = Recommendation::query()->count();

    $this->postJson('/admin/emulator/dry-run', [
        'session_id' => $session->id,
        'lat' => EMU_LILJEHOLMEN['lat'],
        'lng' => EMU_LILJEHOLMEN['lng'],
    ])
        ->assertOk()
        ->assertJsonPath('picked.0.name', 'Vinterviken')
        ->assertJsonStructure(['picked', 'held', 'funnel' => ['unreachable', 'held', 'near_misses', 'served'], 'rank_ms']);

    // The pure pass writes nothing (PRD §15.2): no recommendations, no serve budget
    // spent, no trace. Asking what the pipeline WOULD do must not make it a fact.
    expect(Recommendation::query()->count())->toBe($before);
});

it('audit-logs starting and stopping — a forgotten emulation must be traceable', function () {
    $this->actingAs($operator = profilingConsent(User::factory()->superadmin()->create()));

    $this->post('/admin/emulator/sessions', [
        'origin' => EMU_LILJEHOLMEN, 'travel_mode' => 'walk', 'time_budget_minutes' => 60,
    ]);
    $this->delete('/admin/emulator/sessions');

    $logged = DB::table('activity_log')->pluck('description')->all();

    expect($logged)->toContain('emulation.started')
        ->and($logged)->toContain('emulation.stopped')
        ->and(DB::table('activity_log')->where('causer_id', $operator->id)->count())->toBe(2);
});

it('refuses to write an emulated position onto a real session', function () {
    $this->actingAs($operator = profilingConsent(User::factory()->superadmin()->create()));

    // A REAL session — the operator's own afternoon, or anyone's.
    $trip = Trip::factory()->create(['user_id' => $operator->id]);
    $real = ExploreSession::factory()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $operator->id,
    ]);

    /*
     * Without the guard, this endpoint would be a hand-rolled API for injecting
     * fabricated positions into genuine sessions — the exact contamination the whole
     * `context_source` flag exists to prevent, reachable by anyone who could reach the
     * emulator. The emulator may only ever drive a pin, and only its own.
     */
    $this->postJson('/admin/emulator/positions', [
        'session_id' => $real->id,
        'location' => EMU_HORNSTULL,
    ])->assertForbidden();

    expect(ContextEvent::query()->count())->toBe(0);
});

it('does not let one operator drive another operator’s pin', function () {
    $other = profilingConsent(User::factory()->superadmin()->create());
    $trip = Trip::factory()->create(['user_id' => $other->id]);
    $theirs = ExploreSession::factory()->emulated()->at(59.3103, 18.0227)->create([
        'trip_id' => $trip->id, 'user_id' => $other->id,
    ]);

    $this->actingAs(profilingConsent(User::factory()->superadmin()->create()));

    // "The emulation" is not a global: two superadmins debugging at once must not find
    // themselves fighting over the same pin.
    $this->postJson('/admin/emulator/positions', [
        'session_id' => $theirs->id,
        'location' => EMU_HORNSTULL,
    ])->assertForbidden();
});
