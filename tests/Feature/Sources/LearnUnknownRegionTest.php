<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Places\Services\CacheKeys;
use App\Domain\Places\Services\ScoutRunner;
use App\Domain\Places\Services\TileCache;
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
use DateInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
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

it('drops the scouts’ cached emptiness when new places land in a tile', function () {
    $runner = app(ScoutRunner::class);
    $cache = app(TileCache::class);

    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [SKELLEFTEA['lng'], SKELLEFTEA['lat']])->c;

    // The scouts sweep virgin ground and cache what they find: nothing.
    $cache->remember('nature', $cell, 'v2', new DateInterval('P1D'), fn (): array => []);

    expect(Cache::has(CacheKeys::scout('nature', $cell, 'v2')))->toBeTrue();

    /*
     * Then the region is ingested and places land IN THAT TILE.
     *
     * `DbScout`'s TTL is a DAY, and "there is nothing in this hexagon" caches exactly like
     * any other answer — so without this invalidation the scouts go on serving the
     * emptiness for twenty-four hours while the places sit in the table underneath them.
     *
     * The founder watched it happen: 27 canonical places in Skellefteå, and a pipeline log
     * reading "49 tiles (49 hit, 0 filled), 0 candidates". Every tile a hit; every hit
     * empty. The feed said "nothing worth interrupting you for" about a town it had just
     * finished mapping. Every other part of the progressive ingest — nearest-first boxes,
     * the progressive resolve — is theatre without this line.
     */
    $forgotten = $runner->forgetTiles([$cell]);

    expect($forgotten)->toBeGreaterThan(0)
        ->and(Cache::has(CacheKeys::scout('nature', $cell, 'v2')))->toBeFalse();
});

it('keeps saying it is learning even after the first places trickle in', function () {
    Queue::fake();

    $this->actingAs($user = profilingConsent(User::factory()->create()));

    $trip = Trip::factory()->create(['user_id' => $user->id]);
    $session = ExploreSession::factory()->at(SKELLEFTEA['lat'], SKELLEFTEA['lng'])->create([
        'trip_id' => $trip->id, 'user_id' => $user->id, 'time_budget_minutes' => 180,
    ]);

    app(LearnAreaOnSessionStart::class)
        ->handle(new ExploreSessionStarted($session->id, $trip->id, (int) $user->id));

    // One place has landed from the first box. The region is nowhere near done.
    $place = Place::factory()->create(['name' => 'Bodaträsket']);
    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [SKELLEFTEA['lng'], SKELLEFTEA['lat'], $place->id],
    );

    /*
     * `learning` used to be `learning && ! known`, so the instant the FIRST place trickled
     * in the screen decided the area was known and went back to "You're in a good spot —
     * I'm watching the places around you", with thirty-five of fifty-five boxes still
     * outstanding. A region 20 boxes in is not a region we know.
     */
    $this->get("/explore/{$session->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('coverage.learning', true)
            ->where('coverage.region', 'Skellefteå'));
});

it('never sends a traveller’s exact position to Nominatim', function () {
    Queue::fake();
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            'name' => 'Skellefteå',
            'address' => ['city' => 'Skellefteå', 'country_code' => 'se'],
        ]),
    ]);

    // A very precise position — a doorstep, not a town.
    $lat = 64.750731;
    $lng = 20.952812;

    app(LearnAreaIfUnknown::class)($lat, $lng, 5_000, null);

    /*
     * Nominatim is a third party, and the only thing it needs in order to say "this is
     * Skellefteå" is roughly where Skellefteå is. Handing it a traveller's exact position
     * would put a real coordinate in somebody else's logs for no gain at all — the same
     * mistake ROPA's open finding B3 records against Open-Meteo, and it would falsify
     * ROPA §6's claim that the OSM family receives "no user data at all".
     *
     * So it is asked about a res-8 TILE CENTROID (~0.74 km²). The city is the same; the
     * doorstep is not.
     */
    Http::assertSent(function (Request $request) use ($lat, $lng): bool {
        if (! str_contains($request->url(), 'nominatim')) {
            return true;
        }

        $sentLat = (float) ($request->data()['lat'] ?? 0);
        $sentLng = (float) ($request->data()['lon'] ?? 0);

        // Not the person...
        expect($sentLat)->not->toBe($lat)
            ->and($sentLng)->not->toBe($lng)
            // ...but still the right town (a res-8 cell is well under a kilometre across).
            ->and(abs($sentLat - $lat))->toBeLessThan(0.01)
            ->and(abs($sentLng - $lng))->toBeLessThan(0.02);

        return true;
    });
});
