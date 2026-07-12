<?php

declare(strict_types=1);

use App\Domain\Agent\Contracts\LlmClient;
use App\Domain\Curation\Actions\DraftPackFromWorldModel;
use App\Domain\Curation\Enums\CurationStatus;
use App\Domain\Curation\Models\CuratedItem;
use App\Domain\Curation\Services\PackCandidateSelector;
use App\Domain\Places\Models\Place;
use App\Domain\Sources\Models\SourceItem;
use App\Enums\CredibilityTier;
use App\Enums\SourceLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Agent\FakeLlmClient;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E14 — filling a pack's review queue from the world model (CURATION §3–4)
|--------------------------------------------------------------------------
|
| The founder's review hour is the scarcest resource in this project. These
| tests are about spending it well: only places with evidence, weighted toward
| what Google is worst at, and never forty items from one street or one domain.
|
*/

function packPlace(string $name, string $type, string $domain, array $facets, ?string $description, float $lat = 48.8600, float $lng = 2.3500): Place
{
    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c;

    $place = Place::factory()->create([
        'name' => $name, 'type' => $type, 'type_domain' => $domain,
        'facets' => $facets, 'h3_index' => $cell,
        'source_tags' => ['datatourisme' => []],
    ]);

    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [$lng, $lat, $place->id],
    );

    $externalId = 'dt/'.$place->id;

    // A source item carrying (or not carrying) a written description — this is
    // what makes a place draftable at all.
    $item = SourceItem::factory()->create([
        'source' => 'datatourisme',
        'external_id' => $externalId,
        'credibility_tier' => CredibilityTier::Official,
        'license' => SourceLicense::LicenceOuverte,
        'h3_index' => $cell,
        'payload' => [
            'name' => $name, 'lat' => $lat, 'lng' => $lng,
            'type' => $type, 'type_domain' => $domain,
            'alt_names' => [], 'facets' => $facets,
            'source_tags' => $description === null ? [] : ['description' => $description],
            'external_refs' => [], 'taxonomy_version' => 1,
        ],
    ]);

    DB::statement('UPDATE source_items SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [$lng, $lat, $item->id]);

    DB::table('place_source_ids')->insert([
        'place_id' => $place->id, 'source' => 'datatourisme', 'external_id' => $externalId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $place;
}

function merimeeOnlyPlace(string $name, string $type, string $domain, array $facets, float $lat = 48.8700, float $lng = 2.3700): Place
{
    $cell = DB::selectOne('SELECT h3_lat_lng_to_cell(POINT(?, ?), 8)::text AS c', [$lng, $lat])->c;

    $place = Place::factory()->create([
        'name' => $name, 'type' => $type, 'type_domain' => $domain,
        'facets' => $facets, 'h3_index' => $cell, 'source_tags' => ['merimee' => []],
    ]);

    DB::statement('UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [$lng, $lat, $place->id]);

    $externalId = 'PA'.substr((string) $place->id, 0, 8);

    $item = SourceItem::factory()->create([
        'source' => 'merimee',
        'external_id' => $externalId,
        'credibility_tier' => CredibilityTier::Official,
        'license' => SourceLicense::LicenceOuverte,
        'h3_index' => $cell,
        'payload' => [
            'name' => $name, 'lat' => $lat, 'lng' => $lng,
            'type' => $type, 'type_domain' => $domain,
            'alt_names' => [], 'facets' => $facets,
            // A protection record: structured, true, and no prose at all.
            'source_tags' => ['denomination' => $type, 'datation' => '1710', 'protection' => '1862 : classé MH'],
            'external_refs' => [], 'taxonomy_version' => 1,
        ],
    ]);

    DB::statement('UPDATE source_items SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [$lng, $lat, $item->id]);

    DB::table('place_source_ids')->insert([
        'place_id' => $place->id, 'source' => 'merimee', 'external_id' => $externalId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $place;
}

it('puts the place someone WROTE about ahead of the one with only a record', function () {
    // Both are draftable. But Mérimée gives a protection record and no prose, so
    // the best an honest draft can do is "a protected fountain, dated 1710" — true,
    // formulaic, and a review minute spent to reach a rejection. DATAtourisme
    // carries a tourism board's actual sentences.
    //
    // Same domain and same facets on both, so ONLY richness can separate them.
    merimeeOnlyPlace('Fontaine Trogneux', 'fountain', 'architecture_urban', ['history'], 48.8700, 2.3700);
    packPlace('Passage du Grand-Cerf', 'notable_building', 'architecture_urban', ['history'], 'A glazed arcade of 1825, its ironwork still carrying the original sign brackets.', 48.8650, 2.3650);

    $candidates = app(PackCandidateSelector::class)->forRegion('paris', 10);

    expect($candidates[0]->name)->toBe('Passage du Grand-Cerf');
});

it('still drafts the Mérimée-only chapel — it just queues behind the prose', function () {
    // These are the dolmens and chapels Google does not have. They are the point
    // of the source; they are simply not the first call on a review hour.
    merimeeOnlyPlace('Chapelle Saint-Roch', 'chapel', 'religious_sacred', ['history']);

    expect(app(PackCandidateSelector::class)->forRegion('paris', 10))->toHaveCount(1);
});

it('refuses to draft a place nothing is written about', function () {
    // No evidence ⇒ no draft. A draft not written from evidence is a
    // hallucination with a review queue in front of it (conventions/10). It is
    // also a quality filter: if no tourism board or ministry ever wrote a
    // sentence about it, it is probably not the thing to walk to.
    packPlace('Anonymous doorway', 'notable_building', 'architecture_urban', ['history'], null);

    $candidates = app(PackCandidateSelector::class)->forRegion('paris', 10);

    expect($candidates)->toBeEmpty();
});

it('prefers what Google is worst at', function () {
    packPlace('Grand Museum', 'art_museum', 'museum_gallery', ['art'], 'A large museum.', 48.8600, 2.3500);
    packPlace('Backstreet workshop', 'artisan_workshop', 'shops_craft', ['craft', 'offbeat', 'local_life'], 'A bookbinder still working by hand.', 48.8610, 2.3600);

    $candidates = app(PackCandidateSelector::class)->forRegion('paris', 10);

    // The workshop scores on three priority facets, the museum on none. Curating
    // the Louvre is a waste of a review hour — the traveller will find the Louvre.
    expect($candidates[0]->name)->toBe('Backstreet workshop');
});

it('never lets one domain eat the pack', function () {
    // The bug this exists to prevent: the first real Paris draft came back as
    // EIGHT BISTROS. Each was fine; the set was useless.
    for ($i = 0; $i < 10; $i++) {
        packPlace("Bistro {$i}", 'restaurant', 'food_drink', ['food_drink', 'local_life'], "A bistro number {$i}.", 48.86 + $i * 0.004, 2.35 + $i * 0.004);
    }
    for ($i = 0; $i < 5; $i++) {
        packPlace("Chapel {$i}", 'chapel', 'religious_sacred', ['history'], "A chapel number {$i}.", 48.87 + $i * 0.004, 2.36 + $i * 0.004);
    }

    $candidates = app(PackCandidateSelector::class)->forRegion('paris', 6);

    $domains = [];
    foreach ($candidates as $candidate) {
        $place = Place::query()->find($candidate->placeId);
        $domains[] = $place->type_domain->value;
    }

    expect($candidates)->toHaveCount(6)
        // 35% of 6 ⇒ at most 3 from food_drink, even though bistros dominate the
        // priority ranking and there are ten of them.
        ->and(count(array_filter($domains, fn (string $d): bool => $d === 'food_drink')))->toBeLessThanOrEqual(3)
        ->and($domains)->toContain('religious_sacred');
});

it('spreads a pack across the city, not down one street', function () {
    // Six restaurants in ONE tile. A greedy score takes all six and calls it Paris.
    for ($i = 0; $i < 6; $i++) {
        packPlace("Bistro {$i}", 'restaurant', 'food_drink', ['food_drink'], "Bistro {$i}.", 48.8600, 2.3500);
    }

    $candidates = app(PackCandidateSelector::class)->forRegion('paris', 6);

    expect(count($candidates))->toBeLessThanOrEqual(3);   // PER_TILE_CAP
});

it('drafts grounded and versioned, and never reaches a traveller without a verdict', function () {
    /*
     * THE RULE CHANGED HERE, DELIBERATELY.
     *
     * This test used to assert that a draft lands in_review and waits for a human. That
     * gate approved 149 items and rejected zero — which is not a gate, it is a queue,
     * and the work it did (does the claim say only what the evidence says?) is
     * entailment: mechanical, and done better by something that does not get tired at
     * midnight.
     *
     * What has NOT changed is the thing the old rule was protecting: nothing reaches a
     * traveller unchecked. The check is now the verifier, and its verdict is on the row.
     * An approval with no verdict would be the actual regression, so that is what this
     * pins.
     */
    app()->instance(LlmClient::class, new FakeLlmClient('A bookbinder still working by hand.', supported: true));

    $place = packPlace('Backstreet workshop', 'artisan_workshop', 'shops_craft', ['craft'], 'A bookbinder still working by hand.');

    $result = app(DraftPackFromWorldModel::class)('paris', 5);

    expect($result['drafted'])->toBe(1)
        ->and($result['auto_approved'])->toBe(1);

    $item = CuratedItem::query()->sole();

    expect($item->status)->toBe(CurationStatus::Approved)
        ->and($item->verdict['supported'])->toBeTrue()      // checked, not waved through
        ->and($item->verifier_version)->toBe('claim_verification.v1')
        ->and($item->reviewed_by)->toBeNull()               // a machine did this, and says so
        ->and($item->place_id)->toBe($place->id)            // grounded by construction
        ->and($item->authored_by)->toBe('llm')
        ->and($item->prompt_version)->toBe('curated_claim.v1')
        ->and($item->evidence)->toHaveCount(1);             // what the model actually saw
});

it('holds a draft the evidence does not support, for a human', function () {
    // The verifier is the gate now, so it has to be a real one: when it cannot find the
    // claim in the evidence, the item waits for a person. It never rejects — "unsupported"
    // is a question, not an answer.
    app()->instance(LlmClient::class, new FakeLlmClient('A bookbinder still working by hand.', supported: false));

    packPlace('Backstreet workshop', 'artisan_workshop', 'shops_craft', ['craft'], 'A bookbinder still working by hand.');

    $result = app(DraftPackFromWorldModel::class)('paris', 5);

    expect($result['drafted'])->toBe(1)
        ->and($result['auto_approved'])->toBe(0);

    expect(CuratedItem::query()->sole()->status)->toBe(CurationStatus::InReview);
});

it('never re-drafts a place a human has already ruled on', function () {
    app()->instance(LlmClient::class, new FakeLlmClient);

    packPlace('Backstreet workshop', 'artisan_workshop', 'shops_craft', ['craft'], 'A bookbinder still working by hand.');

    app(DraftPackFromWorldModel::class)('paris', 5);
    app(DraftPackFromWorldModel::class)('paris', 5);   // run it again

    // A curator's decision is not something a re-run gets to undo, and a queue
    // full of duplicates is a queue nobody works through.
    expect(CuratedItem::query()->count())->toBe(1);
});
