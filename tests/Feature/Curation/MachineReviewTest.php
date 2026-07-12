<?php

declare(strict_types=1);

use App\Domain\Agent\Contracts\LlmClient;
use App\Domain\Curation\Actions\AutoReviewCuratedItem;
use App\Domain\Curation\Actions\VerifyCuratedItem;
use App\Domain\Curation\Enums\CurationStatus;
use App\Domain\Curation\Models\CuratedItem;
use App\Domain\Curation\Models\Pack;
use App\Domain\Curation\Services\ClaimGuard;
use App\Domain\Places\Models\Place;
use Tests\Feature\Agent\FakeLlmClient;

/*
|--------------------------------------------------------------------------
| The machine reviewer (CURATION §4)
|--------------------------------------------------------------------------
|
| The human gate approved 149 items and rejected zero. That is not a gate, it is a
| queue — and the work it was doing (does this claim say only what the evidence
| says?) is entailment: a comparison between two pieces of text, which is the one
| question a model can answer here WITHOUT becoming a source of facts.
|
| These tests pin the boundaries that make that acceptable:
|
|   · deterministic gates run first, and a gate failure never reaches the model;
|   · the machine can APPROVE, and can only ever ESCALATE — it never rejects;
|   · a verifier that cannot answer is never read as approval;
|   · verifying is not approving: an audit measures without mutating.
|
*/

function curatedItem(array $attributes = []): CuratedItem
{
    $place = Place::factory()->create([
        'name' => 'Hôtel Negresco', 'type' => 'viewpoint', 'type_domain' => 'nature_landscape',
        'facets' => ['architecture'], 'source_tags' => ['osm' => []],
    ]);

    $pack = Pack::query()->firstOrCreate(['region_slug' => 'nice'], ['name' => 'Nice', 'status' => 'draft']);

    return CuratedItem::query()->create([
        'pack_id' => $pack->id,
        'place_id' => $place->id,
        'region_slug' => 'nice',
        'title' => 'Hôtel Negresco',
        'claim' => 'Designed by Edouard Niermans, the building dates to 1912.',
        'facets' => ['architecture'],
        'evidence' => [[
            'source' => 'merimee',
            'excerpt' => 'A protected hôtel de voyageurs dated 1912 by Niermans Edouard (architecte).',
            'license' => 'licence_ouverte',
            'attribution' => 'Base Mérimée, Ministère de la Culture',
        ]],
        'status' => CurationStatus::InReview,
        'authored_by' => 'llm',
        'prompt_version' => 'curated_claim.v1',
        'language' => 'en',
        ...$attributes,
    ]);
}

it('auto-approves a claim whose every assertion is in the evidence', function () {
    app()->instance(LlmClient::class, new FakeLlmClient(supported: true));

    $item = curatedItem();
    $result = app(AutoReviewCuratedItem::class)($item);

    expect($result['status'])->toBe(CurationStatus::Approved)
        ->and($item->fresh()->status)->toBe(CurationStatus::Approved)
        // The verdict is ON THE ROW. An auto-approval that leaves no trace is a shrug:
        // we could neither audit it later nor re-check it when the verifier changes.
        ->and($item->fresh()->verdict['supported'])->toBeTrue()
        ->and($item->fresh()->verdict['assertions'][0]['evidence_span'])->not->toBeNull()
        ->and($item->fresh()->verifier_version)->toBe(VerifyCuratedItem::PROMPT)
        ->and($item->fresh()->verified_at)->not->toBeNull()
        // reviewed_by is a foreign key to users, and NO USER DID THIS. A machine
        // approval must never be indistinguishable from a person's.
        ->and($item->fresh()->reviewed_by)->toBeNull();
});

it('escalates to a human when the evidence does not support the claim — and never rejects', function () {
    app()->instance(LlmClient::class, new FakeLlmClient(supported: false));

    $item = curatedItem();
    $result = app(AutoReviewCuratedItem::class)($item);

    // NOT Rejected. A model saying "unsupported" has found a question, not an answer:
    // the claim may be fine and the evidence merely thin. The machine's power is
    // asymmetric on purpose — it waves things through, or it hands them back.
    expect($result['status'])->toBe(CurationStatus::InReview)
        ->and($item->fresh()->status)->toBe(CurationStatus::InReview)
        ->and($item->fresh()->verdict['reason'])->toContain('not in the evidence');
});

it('never asks the model a question it can answer by looking at the row', function () {
    $fake = new FakeLlmClient(supported: true);
    app()->instance(LlmClient::class, $fake);

    // Ungrounded: there is no place to point at. No prose can fix that, and asking
    // costs money to be told something we already knew.
    $item = curatedItem(['place_id' => null]);

    $verdict = app(VerifyCuratedItem::class)($item);

    expect($verdict['supported'])->toBeFalse()
        ->and($verdict['verifier'])->toBe('guard')
        ->and($verdict['gate_violations'][0])->toContain('ungrounded')
        ->and($fake->calls)->toBeEmpty();   // the model was never called
});

it('refuses perishable facts however well the evidence supports them', function () {
    $guard = app(ClaimGuard::class);

    // These decay. They were true when retrieved and are not true now; they belong to
    // live sources at the edge, never to a sentence written weeks ago.
    foreach ([
        'Open daily from 9am, and entry is free.',
        'Admission costs €12 for adults.',
        'The chapel closes at 17:30 on Sundays.',
    ] as $claim) {
        expect($guard->violations(curatedItem(['claim' => $claim])))
            ->toContain('perishable fact (hours, price or availability) — these come from live sources, never from a claim');
    }
});

it('refuses a claim with no evidence, or evidence nobody can be credited for', function () {
    $guard = app(ClaimGuard::class);

    // A claim with no evidence is the model speaking from memory — the one thing the
    // architecture exists to prevent.
    expect($guard->violations(curatedItem(['evidence' => []])))
        ->toContain('no evidence: the claim has no source to be traced to');

    // CC BY-SA and ODbL REQUIRE attribution. An un-attributable claim cannot be
    // published: that is a licence condition, not a preference.
    expect($guard->violations(curatedItem(['evidence' => [['source' => 'wikipedia', 'excerpt' => 'Something true.']]])))
        ->toContain('evidence without licence or attribution');
});

it('reads a verifier that cannot answer as "ask a human", never as approval', function () {
    app()->instance(LlmClient::class, new FakeLlmClient(fails: true));

    $item = curatedItem();
    $result = app(AutoReviewCuratedItem::class)($item);

    expect($result['status'])->toBe(CurationStatus::InReview)
        ->and($item->fresh()->verdict['verifier'])->toBe('error');
});

it('audits without approving: measuring must not change what it measures', function () {
    app()->instance(LlmClient::class, new FakeLlmClient(supported: true));

    // Already approved by a human. The audit asks whether the machine agrees — and if
    // running the audit re-approved everything, it would prove only that it had run.
    $item = curatedItem(['status' => CurationStatus::Approved, 'reviewed_by' => null]);

    $verdict = app(VerifyCuratedItem::class)($item);

    expect($verdict['supported'])->toBeTrue()
        ->and($item->fresh()->status)->toBe(CurationStatus::Approved)   // unchanged
        ->and($item->fresh()->verdict)->not->toBeNull();                // but recorded
});
