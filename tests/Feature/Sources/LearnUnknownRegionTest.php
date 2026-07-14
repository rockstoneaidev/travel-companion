<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Sources\Actions\DeriveRegionForPosition;
use App\Domain\Sources\Actions\LearnAreaIfUnknown;
use App\Domain\Sources\Models\DerivedRegion;
use App\Domain\Sources\Services\RegionCatalog;
use App\Domain\Trips\Events\ExploreSessionStarted;
use App\Domain\Trips\Models\ExploreSession;
use App\Domain\Trips\Models\Trip;
use App\Jobs\Ingest\BuildRegionWorldModelJob;
use App\Listeners\LearnAreaOnSessionStart;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E48 — learn an area the first time someone actually goes there
|--------------------------------------------------------------------------
|
| The founder dropped a pin in Skellefteå — a town of 35,000, 700 km north of the
| launch region — and the app had nothing to say, because Skellefteå was not in a PHP
| file. `IngestRegion` is a hand-reviewed catalogue (Stockholm + seven French cities)
| and everywhere else was silence.
|
| VISION §1 always claimed the global path existed ("scouts fetch the current tile on
| demand… it needs no region catalog at all"). It did not: every scout reads our own
| `places` table, so it can only find what bulk ingest already put there. This is the
| missing half.
|
*/

const SKELLEFTEA = ['lat' => 64.7507, 'lng' => 20.9528];
const LILJEHOLMEN_E48 = ['lat' => 59.3103, 'lng' => 18.0227];

beforeEach(function () {
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'name' => 'Skellefteå',
            'address' => ['city' => 'Skellefteå', 'country_code' => 'se'],
        ]),
    ]);
});

it('learns a region nobody has ever asked for', function () {
    Queue::fake();

    $user = User::factory()->create();

    $started = app(LearnAreaIfUnknown::class)(SKELLEFTEA['lat'], SKELLEFTEA['lng'], 5_000, (int) $user->id);

    expect($started)->toBeTrue();

    $region = DerivedRegion::query()->sole();

    expect($region->name)->toBe('Skellefteå')
        // The locale is load-bearing, not decorative: the adapters read `name:{locale}`
        // and follow the matching Wikipedia sitelink. A Swedish town that inherited `en`
        // would quietly ingest far less than it should (E13 learned this the hard way).
        ->and($region->locale)->toBe('sv')
        ->and($region->requested_by_user_id)->toBe($user->id)
        // The pin is inside its own region — which sounds obvious and is exactly the kind
        // of arithmetic that silently ends up off by a longitude cosine.
        ->and(SKELLEFTEA['lat'])->toBeBetween($region->south, $region->north)
        ->and(SKELLEFTEA['lng'])->toBeBetween($region->west, $region->east);

    // ...and the build carries the pin, so the boxes are ingested NEAREST-FIRST.
    Queue::assertPushed(BuildRegionWorldModelJob::class, fn (BuildRegionWorldModelJob $job): bool => $job->regionKey === $region->key
        && $job->nearLat === SKELLEFTEA['lat']
        && $job->nearLng === SKELLEFTEA['lng']);
});

it('does not learn an area it already knows', function () {
    Queue::fake();

    // A place in Stockholm — inside the reviewed catalogue.
    $place = Place::factory()->create(['name' => 'Vinterviken']);
    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [LILJEHOLMEN_E48['lng'], LILJEHOLMEN_E48['lat'], $place->id],
    );

    $started = app(LearnAreaIfUnknown::class)(LILJEHOLMEN_E48['lat'], LILJEHOLMEN_E48['lng'], 5_000, null);

    // Silence here is a JUDGEMENT, not an absence. Re-ingesting a region we already have
    // because today's feed happened to be thin would be an hour of Overpass for nothing.
    expect($started)->toBeFalse()
        ->and(DerivedRegion::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('does not mint a second region for the next street over', function () {
    Queue::fake();

    app(LearnAreaIfUnknown::class)(SKELLEFTEA['lat'], SKELLEFTEA['lng'], 5_000, null);

    // 300 m away — inside the box we just derived.
    app(LearnAreaIfUnknown::class)(SKELLEFTEA['lat'] + 0.003, SKELLEFTEA['lng'], 5_000, null);

    // One region, not two. Overlapping regions are how you ingest the same Overpass boxes
    // twice and call it two cities.
    expect(DerivedRegion::query()->count())->toBe(1)
        ->and(app(RegionCatalog::class)->covering(SKELLEFTEA['lat'] + 0.003, SKELLEFTEA['lng']))->not->toBeNull();
});

it('caps how many regions one person can set us ingesting in a day', function () {
    Queue::fake();

    $user = User::factory()->create();

    // Pins scattered far enough apart that each is genuinely a new region.
    for ($i = 0; $i < 8; $i++) {
        app(LearnAreaIfUnknown::class)(60.0 + $i, 20.0 + $i, 5_000, (int) $user->id);
    }

    /*
     * Not paranoia about abuse — registration is allowlisted. It is a bound on a
     * pathological client: a loop dropping pins across a continent would queue a thousand
     * Overpass boxes and make us exactly the kind of citizen that gets an IP banned from a
     * free, community-run API.
     */
    expect(DerivedRegion::query()->count())->toBe(6);
});

it('starts learning when a session opens somewhere unknown, and says so on the screen', function () {
    Queue::fake();

    $this->actingAs($user = profilingConsent(User::factory()->create()));

    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->at(SKELLEFTEA['lat'], SKELLEFTEA['lng'])->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 180,
    ]);

    // The listener runs on ExploreSessionStarted — an event dispatched since E4 with
    // nothing listening to it, and whose own docblock asked for exactly this.
    app(LearnAreaOnSessionStart::class)
        ->handle(new ExploreSessionStarted($session->id, $trip->id, (int) $user->id));

    expect(DerivedRegion::query()->count())->toBe(1);

    // And the screen stops pretending to watch a town it has never heard of.
    $this->get("/explore/{$session->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('coverage.known', false)
            ->where('coverage.learning', true)
            ->where('coverage.region', 'Skellefteå'));
});

it('orders the ingest boxes nearest-first, so the feed lights up before the region finishes', function () {
    $region = app(DeriveRegionForPosition::class)(
        SKELLEFTEA['lat'],
        SKELLEFTEA['lng'],
    );

    $boxes = $region->boxesNearest(SKELLEFTEA['lat'], SKELLEFTEA['lng']);

    $distance = fn ($box): float => (($box->south + $box->north) / 2 - SKELLEFTEA['lat']) ** 2
        + (($box->west + $box->east) / 2 - SKELLEFTEA['lng']) ** 2;

    /*
     * This ordering is the difference between a usable tool and a forty-five-minute shrug.
     * Stockholm is ~45 boxes at roughly a minute each; in grid order, the person standing
     * in the middle of the region sees nothing until it is nearly finished. Nearest-first,
     * the ground under their feet lands in the first minute or two.
     */
    expect($distance($boxes[0]))->toBeLessThan($distance($boxes[count($boxes) - 1]));
});
