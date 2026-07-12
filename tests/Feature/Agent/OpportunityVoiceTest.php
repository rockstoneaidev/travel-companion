<?php

declare(strict_types=1);

use App\Domain\Agent\Contracts\LlmClient;
use App\Domain\Agent\Data\ContextData;
use App\Domain\Agent\Services\AgentOrchestrator;
use App\Domain\Agent\Services\EvidenceBundleBuilder;
use App\Domain\Curation\Enums\CurationStatus;
use App\Domain\Curation\Models\CuratedItem;
use App\Domain\Opportunities\Actions\RecordOpportunityVoice;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Opportunities\Models\OpportunityEvidence;
use App\Domain\Places\Contracts\PlaceLookup;
use App\Domain\Places\Models\Place;
use App\Domain\Recommendations\Services\CostMeter;
use App\Jobs\Enrichment\GenerateOpportunityVoiceJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Agent\FakeLlmClient;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| E12 — the companion voice (conventions/10)
|--------------------------------------------------------------------------
|
| "The LLM is never a source of facts." These tests are the enforcement, not
| the documentation of an intention: the model may only ever phrase what the
| evidence already says, and if there is no evidence it does not get asked.
|
*/

function voicePlace(string $name = 'Färgfabriken'): Place
{
    $place = Place::factory()->create([
        'name' => $name, 'type' => 'gallery', 'type_domain' => 'arts_culture',
        'facets' => ['art'], 'h3_index' => '881f1d4881fffff', 'source_tags' => ['osm' => []],
    ]);

    DB::statement(
        'UPDATE places_core SET location = ST_SetSRID(ST_MakePoint(18.02, 59.31), 4326)::geography WHERE id = ?',
        [$place->id],
    );

    return $place;
}

function voiceOpportunity(Place $place): Opportunity
{
    return Opportunity::factory()->create([
        'place_id' => $place->id,
        'summary' => null,
        'prompt_version' => null,
        'h3_index' => $place->h3_index,
    ]);
}

function approveClaim(Place $place, string $claim): void
{
    CuratedItem::query()->create([
        'place_id' => $place->id,
        'region_slug' => 'stockholm',
        'title' => $place->name,
        'claim' => $claim,
        'facets' => ['art'],
        'evidence' => [['url' => 'https://example.test', 'source_type' => 'wikipedia', 'license' => 'cc-by-sa', 'excerpt' => 'x']],
        'status' => CurationStatus::Approved,
        'authored_by' => 'human',
    ]);
}

it('never asks the model about a place nothing is written about', function () {
    // The failure mode this whole architecture exists to prevent: a model
    // confidently describing a bakery it has never seen data for. If the bundle
    // is empty there is nothing to phrase, and asking anyway IS asking it to
    // invent. So we do not ask.
    $fake = new FakeLlmClient;
    app()->instance(LlmClient::class, $fake);

    $place = voicePlace();          // no curated claim, no source description
    $opportunity = voiceOpportunity($place);

    app(GenerateOpportunityVoiceJob::class, [
        'opportunityId' => $opportunity->id, 'partOfDay' => 'afternoon',
        'travelMode' => 'walk', 'walkMinutes' => 8,
    ])->handle(...voiceDeps());

    expect($fake->calls)->toBeEmpty()
        ->and($opportunity->refresh()->summary)->toBeNull();
});

it('generates a summary from stored evidence, and records what it saw', function () {
    $fake = new FakeLlmClient('An old paint factory, now an exhibition hall.');
    app()->instance(LlmClient::class, $fake);

    $place = voicePlace();
    approveClaim($place, 'Housed in a paint factory from 1889.');
    $opportunity = voiceOpportunity($place);

    app(GenerateOpportunityVoiceJob::class, [
        'opportunityId' => $opportunity->id, 'partOfDay' => 'afternoon',
        'travelMode' => 'walk', 'walkMinutes' => 8,
    ])->handle(...voiceDeps());

    $opportunity->refresh();

    expect($opportunity->summary)->toBe('An old paint factory, now an exhibition hall.')
        ->and($opportunity->prompt_version)->toBe('opportunity_summary.v1');

    // The bundle is stored WITH the output (PRD §12): given a recommendation you
    // can always answer "what did the model actually see?".
    $evidence = OpportunityEvidence::query()->where('opportunity_id', $opportunity->id)->get();

    expect($evidence)->toHaveCount(1)
        ->and($evidence->first()->excerpt)->toBe('Housed in a paint factory from 1889.');
});

it('shows the model the evidence and nothing else — no scores, no distance', function () {
    // The prompt is the wall. A model that sees our confidence score can leak it;
    // a model that sees the distance will repeat it, and then it is a fact WE did
    // not measure. The app owns the numbers.
    $fake = new FakeLlmClient;
    app()->instance(LlmClient::class, $fake);

    $place = voicePlace();
    approveClaim($place, 'Housed in a paint factory from 1889.');
    $opportunity = voiceOpportunity($place);

    app(GenerateOpportunityVoiceJob::class, [
        'opportunityId' => $opportunity->id, 'partOfDay' => 'afternoon',
        'travelMode' => 'walk', 'walkMinutes' => 8,
    ])->handle(...voiceDeps());

    $prompt = $fake->lastUserPrompt();

    expect($prompt)->toContain('Housed in a paint factory from 1889.')
        ->and($prompt)->toContain('Färgfabriken')
        ->and($prompt)->not->toContain('composite')
        ->and($prompt)->not->toContain('confidence')
        ->and($prompt)->not->toContain('personal_fit');

    // And the system prompt carries the rule, every single time.
    expect($fake->calls[0]['system'])->toContain('Generate only from the evidence');
});

it('falls back to the template when the model fails, rather than serving nothing', function () {
    $fake = new FakeLlmClient(fails: true);
    app()->instance(LlmClient::class, $fake);

    $place = voicePlace();
    approveClaim($place, 'Housed in a paint factory from 1889.');
    $opportunity = voiceOpportunity($place);

    app(GenerateOpportunityVoiceJob::class, [
        'opportunityId' => $opportunity->id, 'partOfDay' => 'afternoon',
        'travelMode' => 'walk', 'walkMinutes' => 8,
    ])->handle(...voiceDeps());

    // Summary stays null; the card renders "8 minutes away on foot" — dull, true,
    // and infinitely better than a spinner or a hallucination.
    expect($opportunity->refresh()->summary)->toBeNull()
        ->and($fake->calls)->toHaveCount(1);
});

it('lets a reviewed human claim outrank the model', function () {
    $fake = new FakeLlmClient;
    app()->instance(LlmClient::class, $fake);

    $place = voicePlace();
    $opportunity = voiceOpportunity($place);
    $opportunity->forceFill(['summary' => 'A human wrote this.'])->save();

    app(GenerateOpportunityVoiceJob::class, [
        'opportunityId' => $opportunity->id, 'partOfDay' => 'afternoon',
        'travelMode' => 'walk', 'walkMinutes' => 8,
    ])->handle(...voiceDeps());

    expect($fake->calls)->toBeEmpty()
        ->and($opportunity->refresh()->summary)->toBe('A human wrote this.');
});

it('invalidates the generation when the evidence changes, without anyone busting a cache', function () {
    $place = voicePlace();
    approveClaim($place, 'Housed in a paint factory from 1889.');

    $builder = app(EvidenceBundleBuilder::class);
    $context = new ContextData('afternoon', 'walk', 8);

    $before = $builder->forPlace($place->id, $context)->id();

    // New evidence lands.
    approveClaim($place, 'The chimney is the last one standing in the district.');
    $after = $builder->forPlace($place->id, $context)->id();

    // The bundle id is a hash of the evidence, and the cache key is
    // (prompt_version, bundle_id) — so this IS the invalidation.
    expect($after)->not->toBe($before);
});

it('reports what a generation cost', function () {
    app()->instance(LlmClient::class, new FakeLlmClient);

    $meter = app(CostMeter::class);
    $meter->recordLlmTokens(120);

    expect($meter->llmTokens())->toBe(120);
});

/** @return array<int, object> */
function voiceDeps(): array
{
    return [
        app(EvidenceBundleBuilder::class),
        app(AgentOrchestrator::class),
        app(RecordOpportunityVoice::class),
        app(PlaceLookup::class),
    ];
}
