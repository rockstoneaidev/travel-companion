<?php

declare(strict_types=1);

use App\Domain\Agent\Data\ContextData;
use App\Domain\Agent\Services\EvidenceBundleBuilder;
use App\Domain\Curation\Services\PackCandidateSelector;
use App\Domain\Places\Models\Place;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The selector and the bundle must agree on what evidence IS
|--------------------------------------------------------------------------
|
| They did not, and the disagreement reported success.
|
| PackCandidateSelector was taught that a Wikipedia extract counts as evidence —
| it has to be, because DATAtourisme and Mérimée are both FRENCH, so without
| Wikipedia the home region can never produce a single candidate however many
| times its world model is rebuilt.
|
| EvidenceBundleBuilder was never taught the same thing. Its `excerptFrom()`
| matched `datatourisme` and `merimee` and returned null for everything else.
|
| So the selector offered 562 Stockholm candidates "with evidence", the drafter
| built an EMPTY bundle for every one of them, skipped them all as "evidence too
| thin", and reported a clean run of zero drafts. 4,749 stored Wikipedia extracts
| — the entire narrative layer of every region — could not reach a single draft,
| and both components were behaving exactly as written.
|
| This test is the seam. A place whose ONLY evidence is Wikipedia must be
| selectable AND draftable, or the two halves have drifted apart again.
|
*/

function placeWithOnlyWikipediaEvidence(): Place
{
    $place = Place::factory()->create([
        'name' => 'Storkyrkan',
        'type' => 'church',
        'type_domain' => 'religious_sacred',
        'facets' => ['history'],
        'h3_index' => '881f1d4881fffff',
        'source_tags' => ['osm' => []],
    ]);

    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(18.0707, 59.3255), 4326)::geography WHERE id = ?',
        [$place->id],
    );

    // The concordance: this place is linked to a Wikipedia article...
    DB::table('place_source_ids')->insert([
        'place_id' => $place->id,
        'source' => 'wikipedia',
        'external_id' => 'sv:Storkyrkan',
    ]);

    // ...and the article's intro is stored, exactly as FetchWikipediaExtracts writes it:
    // CC BY-SA, attributed, prose under `source_tags.description`.
    DB::table('source_items')->insert([
        'id' => (string) Str::uuid(),
        'source' => 'wikipedia',
        'external_id' => 'sv:Storkyrkan',
        'license' => 'cc_by_sa',
        'storage_policy' => 'evidence_only',
        'credibility_tier' => 'reference',
        'payload' => json_encode([
            'source_tags' => [
                'url' => 'https://sv.wikipedia.org/wiki/Storkyrkan',
                'description' => 'Storkyrkan är Stockholms domkyrka och den äldsta kyrkan i Gamla stan.',
            ],
        ]),
        'h3_index' => '881f1d4881fffff',
        'source_adapter_version' => 'v1',
        'attribution' => 'Wikipedia contributors, CC BY-SA 4.0',
        'retrieved_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $place;
}

it('lets a Wikipedia extract reach the evidence bundle, not just the candidate list', function () {
    $place = placeWithOnlyWikipediaEvidence();

    // The selector says: this is a candidate, it has evidence.
    $candidates = app(PackCandidateSelector::class)->forRegion('stockholm', 10);

    expect(collect($candidates)->map(fn ($c): string => $c->placeId))->toContain($place->id);

    // ...and the bundle must AGREE. This is the assertion that was false: the builder
    // returned an empty bundle, the drafter skipped the place as "evidence too thin",
    // and a region with 562 candidates produced zero drafts while reporting success.
    $bundle = app(EvidenceBundleBuilder::class)->forPlace(
        $place->id,
        new ContextData('afternoon', 'walk', 8),
    );

    expect($bundle->isEmpty())->toBeFalse()
        ->and($bundle->toPrompt())->toContain('Stockholms domkyrka');
});

it('carries the CC BY-SA attribution with the quote', function () {
    // Wikipedia is quotable WITH attribution and never merged into the core
    // (conventions/09). Evidence that arrives stripped of its licence is evidence we are
    // not allowed to use.
    $place = placeWithOnlyWikipediaEvidence();

    $bundle = app(EvidenceBundleBuilder::class)->forPlace(
        $place->id,
        new ContextData('afternoon', 'walk', 8),
    );

    $item = collect($bundle->toArray())->firstWhere('source', 'wikipedia');

    expect($item)->not->toBeNull()
        ->and($item['license'])->toBe('cc_by_sa')
        ->and($item['attribution'])->toContain('CC BY-SA');
});
