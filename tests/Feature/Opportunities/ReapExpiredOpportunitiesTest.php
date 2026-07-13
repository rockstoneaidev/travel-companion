<?php

declare(strict_types=1);

use App\Domain\Opportunities\Actions\ReapExpiredOpportunities;
use App\Domain\Opportunities\Enums\OpportunityKind;
use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Opportunities\Models\OpportunityEvidence;
use App\Domain\Places\Models\Place;
use App\Domain\Recommendations\Models\Recommendation;
use App\Domain\Sources\Adapters\OsmAdapter;
use App\Enums\CredibilityTier;
use App\Enums\SourceLicense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Archive-on-expiry, never plain delete (VISION.md §2)
|--------------------------------------------------------------------------
|
| A moment that expired is a moment that happened. The reaper moves the
| license-storable subset of time-bound opportunities into the archive and
| only then deletes — history not archived tonight cannot be recovered in
| three years.
|
*/

/** An event opportunity that expired well past the reaper's grace window. */
function reapableEvent(array $attributes = []): Opportunity
{
    return Opportunity::factory()->create([
        'kind' => OpportunityKind::Event,
        'status' => OpportunityStatus::Served,
        'title' => 'Midsummer concert',
        'summary' => 'A brass band in the bandstand, once a year.',
        'window_ends_at' => now()->subDays(10),
        'expires_at' => now()->subDays(10),
        ...$attributes,
    ]);
}

it('archives an expired event before deleting it, snapshotting the place name', function () {
    $place = Place::factory()->create(['name' => 'Vitabergsparken']);
    $opportunity = reapableEvent(['place_id' => $place->id]);

    $report = app(ReapExpiredOpportunities::class)();

    expect($report->archived)->toBe(1)
        ->and($report->reaped)->toBe(1)
        ->and(Opportunity::query()->find($opportunity->id))->toBeNull();

    $archived = DB::table('archived_opportunities')->where('id', $opportunity->id)->first();

    expect($archived)->not->toBeNull()
        ->and($archived->place_name)->toBe('Vitabergsparken')
        ->and($archived->kind)->toBe(OpportunityKind::Event->value)
        ->and($archived->title)->toBe('Midsummer concert')
        ->and($archived->h3_index)->toBe($opportunity->h3_index);
});

it('archives evidence only from sources whose descriptor grants indefinite retention', function () {
    $opportunity = reapableEvent();

    // OSM's descriptor says archivable; a source the registry has never heard
    // of gets the safe default — dropped, not carried along "for now".
    foreach ([OsmAdapter::KEY, 'some_events_api'] as $source) {
        OpportunityEvidence::query()->create([
            'opportunity_id' => $opportunity->id,
            'source' => $source,
            'license' => SourceLicense::Odbl,
            'credibility_tier' => CredibilityTier::Open,
            'excerpt' => 'A bandstand concert listing.',
            'attribution' => '© somebody',
            'retrieved_at' => now()->subDays(11),
        ]);
    }

    $report = app(ReapExpiredOpportunities::class)();

    expect($report->archivedEvidence)->toBe(1)
        ->and(DB::table('archived_opportunity_evidence')->pluck('source')->all())->toBe([OsmAdapter::KEY])
        ->and(DB::table('opportunity_evidence')->count())->toBe(0);
});

it('reaps expired evergreen rows without archiving them', function () {
    // A daily "this park still exists" materialization is not history — the
    // place itself is permanent in places_core.
    $opportunity = Opportunity::factory()->create([
        'kind' => OpportunityKind::Evergreen,
        'expires_at' => now()->subDays(10),
    ]);

    $report = app(ReapExpiredOpportunities::class)();

    expect($report->reaped)->toBe(1)
        ->and($report->archived)->toBe(0)
        ->and(Opportunity::query()->find($opportunity->id))->toBeNull()
        ->and(DB::table('archived_opportunities')->count())->toBe(0);
});

it('leaves live rows and freshly expired rows alone', function () {
    $live = Opportunity::factory()->create([
        'kind' => OpportunityKind::Event,
        'expires_at' => now()->addDay(),
    ]);

    // Expired, but inside the grace window — still reachable by anything
    // holding its id.
    $fresh = Opportunity::factory()->create([
        'kind' => OpportunityKind::Event,
        'expires_at' => now()->subDay(),
    ]);

    $report = app(ReapExpiredOpportunities::class)();

    expect($report->reaped)->toBe(0)
        ->and($report->archived)->toBe(0)
        ->and(Opportunity::query()->find($live->id))->not->toBeNull()
        ->and(Opportunity::query()->find($fresh->id))->not->toBeNull();
});

it('keeps the recommendation trace, with its opportunity link nulled', function () {
    $user = User::factory()->create();
    $opportunity = reapableEvent();

    $recommendation = Recommendation::query()->create([
        'user_id' => $user->id,
        'opportunity_id' => $opportunity->id,
        'position' => 1,
        'scores' => [],
        'score_inputs' => ['candidate' => ['name' => 'Midsummer concert']],
        'scoring_model_version' => 'v1',
        'taxonomy_version' => 1,
        'served_at' => now()->subDays(10),
    ]);

    app(ReapExpiredOpportunities::class)();

    $recommendation->refresh();

    // The moat outlives the thing it was about (the 2026-07-14 trapdoor
    // migration): the trace survives, it just stops claiming to be live.
    expect($recommendation->opportunity_id)->toBeNull();
});

it('is safe to run twice', function () {
    reapableEvent();

    app(ReapExpiredOpportunities::class)();
    $second = app(ReapExpiredOpportunities::class)();

    expect($second->archived)->toBe(0)
        ->and($second->reaped)->toBe(0)
        ->and(DB::table('archived_opportunities')->count())->toBe(1);
});
