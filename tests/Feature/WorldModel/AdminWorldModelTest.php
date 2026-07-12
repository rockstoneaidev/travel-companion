<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Sources\Services\RegionBuildStatus;
use App\Jobs\Ingest\BuildRegionWorldModelJob;
use App\Jobs\Ingest\DraftRegionPackJob;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Admin → World model (ADMIN.md)
|--------------------------------------------------------------------------
|
| Two bugs, and the first one is the worse of the two.
|
*/

function admin(): User
{
    return User::factory()->superadmin()->create();
}

it('reports each region separately, not the same global totals eight times', function () {
    $this->actingAs(admin());

    // A place in Nice, and nothing anywhere else.
    $place = Place::factory()->create(['name' => 'Musée Matisse']);
    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [7.26, 43.71, $place->id],
    );

    $regions = collect(
        $this->get('/admin/world-model')
            ->assertOk()
            ->viewData('page')['props']['regions'],
    )->keyBy('key');

    /*
     * The old page ran `Place::query()->count()` inside a per-region loop and never
     * mentioned the region. So Stockholm, Paris, Nantes, Nice and Dijon all reported
     * the same "16 963 canonical places" — every number was true of the database and
     * false of the row it sat in, which is worse than showing nothing, because it
     * looks like an answer.
     */
    expect($regions['nice']['places'])->toBe(1)
        ->and($regions['stockholm']['places'])->toBe(0)
        ->and($regions['paris']['places'])->toBe(0);
});

it('will not queue a second build of a region already building', function () {
    Queue::fake();
    $this->actingAs(admin());

    $this->post('/admin/world-model/nice/build')->assertRedirect();
    $this->post('/admin/world-model/nice/build')->assertRedirect();
    $this->post('/admin/world-model/nice/build')->assertRedirect();

    // Three presses used to mean three builds of the same city — three times the
    // Overpass traffic, on a volunteer service that rate-limits us, to compute an
    // answer we already had. The button gave no feedback, so of course it was pressed
    // again.
    Queue::assertPushed(BuildRegionWorldModelJob::class, 1);

    expect(app(RegionBuildStatus::class)->isBuilding('nice'))->toBeTrue();
});

it('shows what the build is doing, and frees the button when it is done', function () {
    $this->actingAs(admin());

    $status = app(RegionBuildStatus::class);
    $status->start('nice');
    $status->phase('nice', 'ingest: osm');

    $this->get('/admin/world-model')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('regions.5.key', 'nice')
            ->where('regions.5.build.phase', 'ingest: osm'));

    // Finishing releases the claim: the console stops saying "building" for a region
    // that completed an hour ago, and the button comes back.
    $status->finish('nice');

    expect($status->isBuilding('nice'))->toBeFalse();
});

it('will not fire a second draft while one is running — each press costs money', function () {
    Queue::fake();
    $this->actingAs(admin());

    $this->post('/admin/world-model/nice/draft-pack')->assertRedirect();
    $this->post('/admin/world-model/nice/draft-pack')->assertRedirect();
    $this->post('/admin/world-model/nice/draft-pack')->assertRedirect();

    /*
     * The build button got progress and a guard; the draft button got neither, so
     * pressing it looked exactly like pressing nothing — which is precisely the
     * condition that makes people press again.
     *
     * And double-firing a draft is worse than double-firing a build: every press is
     * N calls to a paid LLM, drafting the same places a second time.
     */
    Queue::assertPushed(DraftRegionPackJob::class, 1);

    expect(app(RegionBuildStatus::class)->isDrafting('nice'))->toBeTrue();
});

it('shows drafting progress, and frees the button when the job is done', function () {
    $this->actingAs(admin());

    $status = app(RegionBuildStatus::class);
    $status->startDraft('nice', 30);

    $this->get('/admin/world-model')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('regions.5.draft.target', 30));

    // A dead draft must not wedge the button forever. The job releases the claim in a
    // `finally`, and again in failed() — the cache TTL would free it eventually, but
    // "eventually" is not a user experience.
    $status->finishDraft('nice');

    expect($status->isDrafting('nice'))->toBeFalse();
});
