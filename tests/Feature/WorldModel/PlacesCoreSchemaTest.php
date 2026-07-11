<?php

declare(strict_types=1);

use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Places\Enums\PlaceType;
use App\Domain\Places\Enums\PlaceTypeDomain;
use App\Domain\Places\Models\Place;
use App\Enums\AppealFacet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| World-model schema smoke — E1 (conventions/03)
|--------------------------------------------------------------------------
*/

it('creates a canonical place with geography, taxonomy casts, and facets', function () {
    $place = Place::factory()->create([
        'type' => PlaceType::Chapel,
        'type_domain' => PlaceTypeDomain::ReligiousSacred,
        'facets' => PlaceType::Chapel->baseFacets(),
    ]);

    $place->refresh();

    expect($place->type)->toBe(PlaceType::Chapel)
        ->and($place->type_domain)->toBe(PlaceTypeDomain::ReligiousSacred)
        ->and($place->facets->contains(AppealFacet::Spiritual))->toBeTrue()
        ->and($place->facets->contains(AppealFacet::Architecture))->toBeTrue();

    // The geography column is real PostGIS: distance math works in meters.
    $meters = DB::table('places_core')
        ->where('id', $place->id)
        ->selectRaw("ST_Distance(location, ST_GeogFromText('POINT(18.02 59.31)')) as meters")
        ->value('meters');

    expect((float) $meters)->toBeGreaterThanOrEqual(0.0)->toBeLessThan(20_000.0);
});

it('links opportunities to canonical places and rounds the state machine', function () {
    $opportunity = Opportunity::factory()->create();

    expect($opportunity->status)->toBe(OpportunityStatus::RawCandidate)
        ->and($opportunity->place_id)->not->toBeNull()
        ->and($opportunity->expires_at->isFuture())->toBeTrue();

    $opportunity->update(['status' => OpportunityStatus::Discarded]);

    expect($opportunity->refresh()->status)->toBe(OpportunityStatus::Discarded)
        ->and(in_array($opportunity->status, OpportunityStatus::terminal(), true))->toBeTrue();
});

it('keeps the concordance unique per source identity', function () {
    $place = Place::factory()->create();

    $place->sourceIds()->create(['source' => 'osm', 'external_id' => 'node/42']);

    expect(fn () => Place::factory()->create()->sourceIds()->create([
        'source' => 'osm', 'external_id' => 'node/42',
    ]))->toThrow(QueryException::class);
});
