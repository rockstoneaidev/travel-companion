<?php

declare(strict_types=1);

use App\Domain\Places\Models\Place;
use App\Domain\Places\Models\PlaceMatchDecision;
use App\Domain\Places\Models\PlaceMerge;
use App\Domain\Places\Queries\ReviewQueue;
use App\Domain\Places\Services\EntityResolver;
use App\Domain\Sources\Models\SourceItem;
use App\Enums\CredibilityTier;
use App\Enums\SourceLicense;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

const REVIEW_TILE = '8808866189fffff';

/*
|--------------------------------------------------------------------------
| The entity-resolution review queue (ENTITY-RESOLUTION §3 stage 4)
|--------------------------------------------------------------------------
|
| Review-band pairs were being persisted and then never surfaced to anyone.
| Both places stay live and serveable — but a human has to break the tie, or
| the world model silently keeps a probable duplicate forever.
|
*/

function reviewQueueItem(string $source, string $externalId, string $name, float $lat, float $lng): void
{
    $item = SourceItem::factory()->create([
        'source' => $source,
        'external_id' => $externalId,
        'credibility_tier' => CredibilityTier::Open,
        'license' => SourceLicense::Odbl,
        'h3_index' => REVIEW_TILE,
        'payload' => [
            'name' => $name, 'lat' => $lat, 'lng' => $lng,
            'type' => 'cafe', 'type_domain' => 'food_drink',
            'alt_names' => [], 'facets' => [], 'source_tags' => [],
            'external_refs' => [], 'taxonomy_version' => 1,
        ],
    ]);

    DB::statement(
        'UPDATE source_items SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [$lng, $lat, $item->id],
    );
}

function resolveTwoChainCafes(): void
{
    // Same name, same type, ~200 m apart: the resolver must NOT guess.
    reviewQueueItem('osm', 'node/1', 'Espresso House', 59.32550, 18.07080);
    reviewQueueItem('overture', 'ov/2', 'Espresso House', 59.32700, 18.07300);

    app(EntityResolver::class)->resolveTile(REVIEW_TILE);
}

it('surfaces the pairs the resolver refused to guess about', function () {
    resolveTwoChainCafes();

    $pairs = app(ReviewQueue::class)->pending();

    expect($pairs)->toHaveCount(1)
        ->and(app(ReviewQueue::class)->pendingCount())->toBe(1);

    $pair = $pairs[0];

    expect($pair->candidatePlaceName)->toBe('Espresso House')
        ->and($pair->comparedPlaceName)->toBe('Espresso House')
        ->and($pair->candidatePlaceId)->not->toBe($pair->comparedPlaceId)
        ->and($pair->distanceMeters)->toBeGreaterThan(100)
        ->and($pair->score)->not->toBeNull();
});

it('merges a reviewed pair on the human\'s say-so, leaving a redirect behind', function () {
    resolveTwoChainCafes();

    $pair = app(ReviewQueue::class)->pending()[0];
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->put("/admin/entity-resolution/{$pair->decisionId}/merge", [
            'candidate_place_id' => $pair->candidatePlaceId,
            'compared_place_id' => $pair->comparedPlaceId,
        ])
        ->assertRedirect();

    // One canonical place survives, and the loser is a redirect — never deleted.
    expect(Place::query()->count())->toBe(1)
        ->and(PlaceMerge::query()->where('old_place_id', $pair->candidatePlaceId)->exists())->toBeTrue()
        ->and(app(ReviewQueue::class)->pendingCount())->toBe(0);

    $decision = PlaceMatchDecision::query()->find($pair->decisionId);
    expect($decision->decided_by)->toBe('human')
        ->and($decision->signals['human_outcome'])->toBe('merged');
});

it('records "these really are different" and stops asking', function () {
    resolveTwoChainCafes();

    $pair = app(ReviewQueue::class)->pending()[0];
    $admin = User::factory()->superadmin()->create();

    $this->actingAs($admin)
        ->put("/admin/entity-resolution/{$pair->decisionId}/distinct")
        ->assertRedirect();

    // Two branches of a chain really are two places. Both survive.
    expect(Place::query()->count())->toBe(2)
        ->and(PlaceMerge::query()->count())->toBe(0)
        ->and(app(ReviewQueue::class)->pendingCount())->toBe(0);

    expect(PlaceMatchDecision::query()->find($pair->decisionId)->signals['human_outcome'])->toBe('distinct');
});

it('is not reachable without admin access', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/entity-resolution')
        ->assertForbidden();
});
