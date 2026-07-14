<?php

declare(strict_types=1);

use App\Domain\Context\Services\GoogleHoursVerifier;
use App\Domain\Places\Models\Place;
use App\Domain\Places\Models\PlaceSourceId;
use App\Domain\Places\Services\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E16 — verify-before-recommend (conventions/09, ODBL-REVIEW §6)
|--------------------------------------------------------------------------
|
| The constraint most likely to be broken by accident, so it gets its own
| tests. Google data is EDGE-ONLY: the ONLY value that may reach a database is
| the place_id string. Not the name, not the rating, and not the hours —
| however tempting it is to cache them "just for a day".
|
| Persisting it is not a code-review nit. It is simultaneously a Google ToS
| breach and an ODbL one: proprietary data mixed into an ODbL Derivative
| Database poisons it.
|
*/

beforeEach(function () {
    config()->set('services.google.maps_key', 'test-key');
});

function fakeGoogle(bool $openNow, ?string $closesAt = null): void
{
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => Http::response(['places' => [['id' => 'ChIJgoogle123']]]),
        'places.googleapis.com/v1/places/*' => Http::response([
            'currentOpeningHours' => array_filter([
                'openNow' => $openNow,
                'nextCloseTime' => $closesAt,
            ], static fn ($v): bool => $v !== null),
        ]),
    ]);
}

it('knows when a place is open, and when it shuts', function () {
    fakeGoogle(openNow: true, closesAt: '2026-08-02T18:00:00Z');

    $place = Place::factory()->create(['name' => 'Musée Matisse']);

    $hours = app(GoogleHoursVerifier::class)->forPlace($place->id, 'Musée Matisse', 43.71, 7.26);

    expect($hours->known)->toBeTrue()
        ->and($hours->openNow)->toBeTrue()
        ->and($hours->definitelyClosed())->toBeFalse()
        ->and($hours->closesAt?->toIso8601String())->toContain('2026-08-02');
});

it('stores the place_id and NOTHING else — this is a licensing boundary', function () {
    fakeGoogle(openNow: true, closesAt: '2026-08-02T18:00:00Z');

    $place = Place::factory()->create(['name' => 'Musée Matisse']);

    app(GoogleHoursVerifier::class)->forPlace($place->id, 'Musée Matisse', 43.71, 7.26);

    // The one Google value we are allowed to keep.
    $stored = PlaceSourceId::query()->where('place_id', $place->id)->where('source', 'google')->sole();
    expect($stored->external_id)->toBe('ChIJgoogle123');

    // ...and the hours are NOT in the database. Anywhere. The edge cache is the
    // only place they may live (conventions/09).
    foreach (['places_core', 'opportunities', 'place_source_ids'] as $table) {
        $dump = json_encode(DB::table($table)->get());

        expect($dump)->not->toContain('openNow')
            ->and($dump)->not->toContain('nextCloseTime')
            ->and($dump)->not->toContain('currentOpeningHours');
    }

    // They live here, and only here, under a short TTL.
    expect(Cache::get("place:hours:{$place->id}"))->not->toBeNull();
});

it('does not pay twice for a place_id that cannot change', function () {
    fakeGoogle(openNow: true);

    $place = Place::factory()->create(['name' => 'Musée Matisse']);
    $verifier = app(GoogleHoursVerifier::class);

    $verifier->forPlace($place->id, 'Musée Matisse', 43.71, 7.26);
    Cache::flush();                                            // hours expire...
    $verifier->forPlace($place->id, 'Musée Matisse', 43.71, 7.26);

    // ...but the place_id does not. Two hours lookups, ONE text search.
    Http::assertSentCount(3);

    expect(PlaceSourceId::query()->where('source', 'google')->count())->toBe(1);
});

it('treats unknown hours as unknown — never as closed', function () {
    // Most of the OSM long tail has no hours published anywhere. Reading silence as
    // "shut" would quietly delete the entire long tail from the feed — the exact
    // layer this product exists to surface.
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => Http::response(['places' => []]),
    ]);

    $place = Place::factory()->create(['name' => 'A fountain with no hours']);

    $hours = app(GoogleHoursVerifier::class)->forPlace($place->id, 'A fountain with no hours', 43.71, 7.26);

    expect($hours->known)->toBeFalse()
        ->and($hours->definitelyClosed())->toBeFalse();
});

it('stays quiet, and costs nothing, when there is no key', function () {
    config()->set('services.google.maps_key', '');
    Http::fake();

    $place = Place::factory()->create(['name' => 'Musée Matisse']);

    $hours = app(GoogleHoursVerifier::class)->forPlace($place->id, 'Musée Matisse', 43.71, 7.26);

    // An absent key is a supported state, not a broken one: hours are simply
    // unknown and the feed is served without them.
    expect($hours->known)->toBeFalse();
    Http::assertNothingSent();
});

it('serves the feed without hours rather than not at all when Google is down', function () {
    Http::fake(['places.googleapis.com/*' => Http::response('boom', 503)]);

    $place = Place::factory()->create(['name' => 'Musée Matisse']);

    $hours = app(GoogleHoursVerifier::class)->forPlace($place->id, 'Musée Matisse', 43.71, 7.26);

    // Degraded, not failed (SCORING §2.5). A recommendation missing its hours is a
    // recommendation; one that never arrives is not.
    expect($hours->known)->toBeFalse()
        ->and($hours->definitelyClosed())->toBeFalse();
});

it('will not let one bad Google match take down the feed', function () {
    // A text search that matches the wrong place is not hypothetical — and
    // place_source_ids is unique on (source, external_id), rightly, because one
    // Google entity is one real place. Two of our places resolving to the SAME
    // Google id used to throw a UniqueConstraintViolation and kill the entire
    // request: a whole feed lost because one search picked the wrong café.
    fakeGoogle(openNow: true);

    $first = Place::factory()->create(['name' => 'Café du Port']);
    $second = Place::factory()->create(['name' => 'Café du Port (the other one)']);

    $verifier = app(GoogleHoursVerifier::class);

    $verifier->forPlace($first->id, 'Café du Port', 43.71, 7.26);
    $hours = $verifier->forPlace($second->id, 'Café du Port (the other one)', 43.71, 7.26);

    // The second one does not crash, does not steal the mapping, and does not claim
    // to know the hours — because at least one of the two matches is wrong and we
    // cannot tell which.
    expect($hours->known)->toBeFalse()
        ->and($hours->definitelyClosed())->toBeFalse()
        ->and(PlaceSourceId::query()->where('source', 'google')->count())->toBe(1);
});

it('caches "this place has no hours" — the answer that used to be re-bought forever', function () {
    /*
     * The single most expensive line in the product, and it looked like nothing.
     *
     * Google returns no `currentOpeningHours` for most of the long tail — parks,
     * churches, viewpoints, the whole layer this product exists to surface. That came
     * back as `null`; `null` was indistinguishable from "the call failed"; nothing was
     * cached. So every rank re-asked Google about the same park and paid $0.005 to be
     * told, again, that it still has no opening hours.
     *
     * The founder drove ONE emulated walk and it cost $0.31 — sixty-eight `place_details`
     * calls, not one of them a cache hit, almost all about a handful of parks in Kista.
     */
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => Http::response(['places' => [['id' => 'ChIJpark']]]),
        // A park. Google knows it exists and knows of no hours for it.
        'places.googleapis.com/v1/places/*' => Http::response(['id' => 'ChIJpark']),
    ]);

    $place = Place::factory()->create(['name' => 'Bagarbyparken']);
    $verifier = app(GoogleHoursVerifier::class);

    $first = $verifier->forPlace($place->id, 'Bagarbyparken', 59.31, 18.02);
    $second = $verifier->forPlace($place->id, 'Bagarbyparken', 59.31, 18.02);
    $third = $verifier->forPlace($place->id, 'Bagarbyparken', 59.31, 18.02);

    // "Unknown" is not "closed" — a park with no hours must still be servable.
    expect($first->known)->toBeFalse()
        ->and($second->known)->toBeFalse()
        ->and($third->known)->toBeFalse();

    // ONE details call, not three. The re-anchoring feed asks this question dozens of
    // times a minute about the same handful of places.
    Http::assertSentCount(2);   // one searchText (the place_id lookup) + one details
});

it('does not cache a FAILURE as an answer', function () {
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => Http::response(['places' => [['id' => 'ChIJpark']]]),
        'places.googleapis.com/v1/places/*' => Http::response(status: 500),
    ]);

    $place = Place::factory()->create(['name' => 'Bagarbyparken']);
    $verifier = app(GoogleHoursVerifier::class);

    $verifier->forPlace($place->id, 'Bagarbyparken', 59.31, 18.02);

    // A timeout is not "this place has no hours". Caching it would let one bad minute
    // poison ten — so the cache stays empty and the next caller may try again.
    expect(Cache::get(CacheKeys::placeHours($place->id)))->toBeNull();
});
