<?php

declare(strict_types=1);

use App\Domain\Curation\Data\PackPlan;
use App\Domain\Curation\Enums\CurationStatus;
use App\Domain\Curation\Models\CuratedItem;
use App\Domain\Curation\Models\Pack;
use App\Domain\Places\Models\Place;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Publishing a Regional Knowledge Pack (CURATION §3 step 5)
|--------------------------------------------------------------------------
|
| PublishPack had no callers: a pack could be drafted, grounded and reviewed
| and still never ship, because nothing could set it to published.
|
*/

function packWithApproved(int $approved, string $region = 'stockholm'): Pack
{
    $pack = Pack::query()->create([
        'region_slug' => $region,
        'name' => 'Stockholm',
        'status' => 'draft',
        'pack_version' => 0,
        'h3_set' => [],
        'effort_minutes' => 0,
    ]);

    for ($i = 0; $i < $approved; $i++) {
        $place = Place::factory()->create(['h3_index' => '88'.str_pad((string) $i, 13, '0', STR_PAD_LEFT)]);
        DB::statement(
            'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(18.02, 59.31), 4326)::geography WHERE id = ?',
            [$place->id],
        );

        CuratedItem::query()->create([
            'pack_id' => $pack->id,
            'place_id' => $place->id,
            'region_slug' => $region,
            'title' => "Item {$i}",
            'claim' => 'A true thing.',
            'facets' => ['history'],
            'evidence' => [['url' => 'https://example.test', 'source_type' => 'wikipedia', 'license' => 'cc-by-sa', 'excerpt' => 'x']],
            'status' => CurationStatus::Approved,
            'authored_by' => 'human',
        ]);
    }

    return $pack->refresh();
}

it('publishes a pack, bumping the version and mapping its tiles', function () {
    // Seeded to the region's PLANNED target (CURATION §4), not to a number that happened
    // to equal the old flat gate. The gate used to be 25 for every region; Stockholm's
    // plan is 30, and the two agreeing by coincidence is what let them drift apart.
    packWithApproved(PackPlan::targetFor('stockholm'));

    $this->artisan('curation:publish', ['region' => 'stockholm', '--effort' => 90])
        ->assertSuccessful();

    $pack = Pack::query()->where('region_slug', 'stockholm')->sole();

    expect($pack->status)->toBe('published')
        ->and($pack->pack_version)->toBe(1)
        ->and($pack->h3_set)->toHaveCount(PackPlan::targetFor('stockholm'))
        ->and($pack->effort_minutes)->toBe(90);   // the Phase-3 cost model's only input
});

it('refuses to ship a pack with almost nothing approved in it', function () {
    // The live stockholm pack is in exactly this state: 2 approved of 40.
    packWithApproved(2);

    $this->artisan('curation:publish', ['region' => 'stockholm'])
        ->assertFailed();

    expect(Pack::query()->sole()->status)->toBe('draft');
});

it('publishes an under-target pack when that is deliberate', function () {
    packWithApproved(2);

    $this->artisan('curation:publish', ['region' => 'stockholm', '--force' => true])
        ->assertSuccessful();

    expect(Pack::query()->sole()->status)->toBe('published');
});

it('accumulates review effort across versions', function () {
    // Seeded to the region's PLANNED target (CURATION §4), not to a number that happened
    // to equal the old flat gate. The gate used to be 25 for every region; Stockholm's
    // plan is 30, and the two agreeing by coincidence is what let them drift apart.
    packWithApproved(PackPlan::targetFor('stockholm'));

    $this->artisan('curation:publish', ['region' => 'stockholm', '--effort' => 90])->assertSuccessful();
    $this->artisan('curation:publish', ['region' => 'stockholm', '--effort' => 30])->assertSuccessful();

    $pack = Pack::query()->sole();

    expect($pack->pack_version)->toBe(2)
        ->and($pack->effort_minutes)->toBe(120);
});
