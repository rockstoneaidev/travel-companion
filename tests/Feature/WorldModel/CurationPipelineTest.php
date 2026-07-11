<?php

declare(strict_types=1);

use App\Domain\Curation\Actions\DraftCuratedItems;
use App\Domain\Curation\Actions\GroundCuratedItem;
use App\Domain\Curation\Actions\ReviewCuratedItem;
use App\Domain\Curation\Enums\CurationStatus;
use App\Domain\Curation\Services\ApprovedCuratedItems;
use App\Domain\Places\Models\Place;
use App\Domain\Places\Services\Scouts\CuratedScout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E11 — the curated layer: draft → ground → review gate → scout
|--------------------------------------------------------------------------
*/

function harvestRow(string $title): array
{
    return [
        'title' => $title,
        'claim' => 'A small climb locals use for the best sunset over Riddarfjärden.',
        'facets' => ['scenic', 'offbeat'],
        'evidence' => [[
            'url' => 'https://en.wikivoyage.org/wiki/Stockholm/S%C3%B6dermalm',
            'source_type' => 'wikivoyage', 'license' => 'CC BY-SA 4.0',
            'excerpt' => 'A rocky hill with a sweeping view over Lake Mälaren.', 'retrieved_at' => now()->toIso8601String(),
        ]],
    ];
}

function seedCuratedPlace(): Place
{
    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(18.0605, 59.3197), 8)::text AS c')->c;
    $place = Place::factory()->create(['name' => 'Skinnarviksberget', 'type' => 'viewpoint', 'type_domain' => 'nature_landscape', 'h3_index' => $cell]);
    DB::statement('UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(18.0605, 59.3197), 4326)::geography WHERE id = ?', [$place->id]);

    return $place;
}

it('grounds drafts against canonical places and gates approval on grounding', function () {
    $place = seedCuratedPlace();
    $reviewer = User::factory()->create();

    [$matched] = app(DraftCuratedItems::class)('stockholm-test', [harvestRow('Skinnarviksberget')]);
    [$unmatched] = app(DraftCuratedItems::class)('stockholm-test', [harvestRow('Nonexistent Grotto of Xyzzy')]);

    app(GroundCuratedItem::class)($matched);
    app(GroundCuratedItem::class)($unmatched);

    expect($matched->refresh()->status)->toBe(CurationStatus::InReview)
        ->and($matched->place_id)->toBe($place->id)
        ->and($unmatched->refresh()->status)->toBe(CurationStatus::NeedsGrounding);

    // Approving an ungrounded claim is a structural error, not a warning.
    expect(fn () => app(ReviewCuratedItem::class)->approve($unmatched, $reviewer->id))
        ->toThrow(InvalidArgumentException::class);
});

it('serves ONLY approved items through the scout — the review gate is the moat', function () {
    $place = seedCuratedPlace();
    $reviewer = User::factory()->create();

    [$item] = app(DraftCuratedItems::class)('stockholm-test', [harvestRow('Skinnarviksberget')]);
    app(GroundCuratedItem::class)($item);

    // In review: invisible to the scout, whatever the cache thinks.
    expect(app(CuratedScout::class)->candidatesForTile($place->h3_index))->toBeEmpty();

    app(ReviewCuratedItem::class)->approve($item->refresh(), $reviewer->id);

    $candidates = app(CuratedScout::class)->candidatesForTile($place->h3_index);

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]['sources'])->toBe(['curated'])            // Tier A after review
        ->and($candidates[0]['facets'])->toBe(['scenic', 'offbeat'])   // the curator's facets, not priors
        ->and($candidates[0]['curated_claim'])->toContain('sunset');

    // Rejected items disappear again.
    app(ReviewCuratedItem::class)->reject($item->refresh(), $reviewer->id);
    expect(app(ApprovedCuratedItems::class)->forTile($place->h3_index))->toBeEmpty();
});

it('locks the review queue behind admin access', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/curation')
        ->assertForbidden();
});
